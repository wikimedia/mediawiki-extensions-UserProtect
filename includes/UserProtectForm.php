<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use OOUI\ProgressBarWidget;

class UserProtectForm {

	/** @var int */
	private const TYPE_REMOVED = 0;

	/** @var int */
	private const TYPE_ADDED = 1;

	/** @var array Permissions errors for the protect action */
	private $permErrors = [];

	/** @var Title */
	private $title;

	/** @var bool */
	private $disabled;

	/** @var array */
	private $disabledAttrib;

	/** @var array */
	private $rights;

	/** @var Action */
	private $action;

	/** @var PermissionManager */
	private $permManager;

	/** @var IContextSource */
	private $context;

	/**
	 * UserProtectForm constructor.
	 *
	 * @param Action $action
	 */
	public function __construct( Action $action ) {
		// Set instance variables.
		$this->action = $action;
		$this->title = $action->getTitle();
		$this->context = $action->getContext();

		// Check if the form should be disabled.
		// If it is, the form will be available in read-only to show levels.
		$services = MediaWikiServices::getInstance();
		$this->permManager = $services->getPermissionManager();
		$rigor = $this->context->getRequest()->wasPosted()
			? PermissionManager::RIGOR_SECURE
			: PermissionManager::RIGOR_FULL;
		$this->permErrors = $this->permManager->getPermissionErrors(
			'userprotect',
			$action->getUser(),
			$this->title,
			$rigor
		);
		$readOnlyMode = $services->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			$this->permErrors[] = [ 'readonlytext', $readOnlyMode->getReason() ];
		}
		$this->disabled = $this->permErrors !== [];
		$this->disabledAttrib = $this->disabled
			? [ 'disabled' => 'disabled' ]
			: [];

		$this->loadData();
	}

	/**
	 * Main entry point for action=userprotect
	 *
	 * @throws ErrorPageError
	 * @throws MWException
	 */
	public function execute() {
		if ( $this->permManager->getNamespaceRestrictionLevels(
				$this->title->getNamespace()
			) === [ '' ]
		) {
			throw new ErrorPageError( 'protect-badnamespace-title', 'protect-badnamespace-text' );
		}

		if ( $this->context->getRequest()->wasPosted() ) {
			if ( !$this->save() ) {
				// $this->show() called already
				return;
			}
			// Reload data from the database
			$this->rights = [];
			$this->loadData();
		}
		$this->show();
	}

	/**
	 * Loads the current state of protection into the object
	 */
	private function loadData() {
		$pageId = $this->title->getArticleID();
		$applicableTypes = UserProtectPermissionManager::getApplicableTypes( (bool)$pageId );
		$applicableTypes[] = 'all';
		$tables = [ 'user' ];
		$vars = [ 'user_name' ];
		if ( $pageId ) {
			$tables[] = 'user_protect_rights';
			$vars['added'] = 'upr_added';
			$vars['type'] = 'upr_type';
			$conds = [
				'upr_page' => $pageId,
				'upr_type' => $applicableTypes,
				'upr_user = user_id',
			];
		} else {
			$tables[] = 'user_protect_titles';
			$vars['added'] = 'upt_added';
			$vars['type'] = 'upt_type';
			$conds = [
				'upt_namespace' => $this->title->getNamespace(),
				'upt_title' => $this->title->getDBkey(),
				'upt_type' => $applicableTypes,
				'upt_user = user_id',
			];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( $tables, $vars, $conds, __METHOD__ );
		foreach ( $res as $row ) {
			$this->rights[$row->added][$row->type][] = $row->user_name;
		}
	}

	/**
	 * Save submitted form
	 *
	 * @return bool
	 * @throws MWException
	 */
	private function save(): bool {
		// Permission check!
		if ( $this->disabled ) {
			$this->show();
			return false;
		}

		$title = $this->title;
		$request = $this->context->getRequest();
		$contextUser = $this->context->getUser();
		$token = $request->getVal( 'userprotect-token' );
		if ( !$contextUser->matchEditToken( $token, [ 'userprotect', $title->getPrefixedDBkey() ] ) ) {
			$this->show( [ 'sessionfailure' ] );
			return false;
		}

		$removeAll = self::getUserNames( $request, 'remove-users-all' );
		$addAll = self::getUserNames( $request, 'add-users-all' );
		$addAll = array_diff( $addAll, $removeAll );
		$userNames = array_merge( $removeAll, $addAll );
		$remove = [];
		$add = [];
		$applicableTypes = UserProtectPermissionManager::getApplicableTypes( $title->exists() );
		foreach ( $applicableTypes as $type ) {
			$removeValue = self::getUserNames( $request, "remove-users-$type" );
			$remove[$type] = array_diff( $removeValue, $removeAll );
			$addValue = self::getUserNames( $request, "add-users-$type" );
			$add[$type] = array_diff( $addValue, $addAll, $removeAll, $remove[$type] );
			if ( $remove[$type] || $add[$type] ) {
				$userNames = array_merge( $userNames, $remove[$type], $add[$type] );
			}
		}

		$dbw = wfGetDB( DB_PRIMARY );

		if ( $title->exists() ) {
			$tableName = 'user_protect_rights';
			$deleteConds = [ 'upr_page' => $title->getArticleID() ];
		} else {
			$tableName = 'user_protect_titles';
			$deleteConds = [
				'upt_namespace' => $title->getNamespace(),
				'upt_title' => $title->getDBkey(),
			];
		}

		$dbw->startAtomic( __METHOD__ );
		$dbw->delete( $tableName, $deleteConds, __METHOD__ );

		$users = self::getUsersByName( $userNames );
		if ( !$users ) {
			$dbw->endAtomic( __METHOD__ );
			return true;
		}

		$timestamp = wfTimestampNow();
		$rows = [];
		$this->addRowsForType( $users, $removeAll, 'all', self::TYPE_REMOVED, $timestamp, $rows );
		$this->addRowsForType( $users, $addAll, 'all', self::TYPE_ADDED, $timestamp, $rows );
		foreach ( $remove as $type => $removeValue ) {
			$this->addRowsForType( $users, $removeValue, $type, self::TYPE_REMOVED, $timestamp, $rows );
			$this->addRowsForType( $users, $add[$type], $type, self::TYPE_ADDED, $timestamp, $rows );
		}
		// @phan-suppress-next-line SecurityCheck-SQLInjection
		$dbw->insert( $tableName, $rows, __METHOD__ );

		$dbw->endAtomic( __METHOD__ );
		return true;
	}

	/**
	 * Builds row for insertion to the database and adds it to the $rows variable
	 *
	 * @param array $users
	 * @param array $userNames
	 * @param string $type
	 * @param int $added
	 * @param string $timestamp
	 * @param array &$rows
	 */
	private function addRowsForType(
		array $users, array $userNames, string $type,
		int $added, string $timestamp, array &$rows
	) {
		$title = $this->title;
		$pageId = $title->getArticleID();
		$dbKey = $title->getDBkey();
		$namespace = $title->getNamespace();

		foreach ( $userNames as $name ) {
			if ( isset( $users[$name] ) ) {
				$userId = $users[$name];
				if ( $pageId ) {
					$rows[] = [
						'upr_page' => $pageId,
						'upr_type' => $type,
						'upr_user' => $userId,
						'upr_added' => $added,
						'upr_timestamp' => $timestamp,
					];
				} else {
					$rows[] = [
						'upt_namespace' => $namespace,
						'upt_title' => $dbKey,
						'upt_type' => $type,
						'upt_user' => $userId,
						'upt_added' => $added,
						'upt_timestamp' => $timestamp,
					];
				}
			}
		}
	}

	/**
	 * Show the input form with optional error message
	 *
	 * @param string|array|null $err
	 * @throws MWException
	 */
	private function show( $err = null ) {
		$title = $this->title;
		$context = $this->context;
		$out = $context->getOutput();
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->addBacklinkSubtitle( $title );

		if ( is_array( $err ) ) {
			$out->wrapWikiMsg( "<div class='error'>\n$1\n</div>\n", $err );
		} elseif ( is_string( $err ) ) {
			$out->addHTML( "<div class='error'>{$err}</div>\n" );
		}

		if ( $title->getRestrictionTypes() === [] ) {
			// No restriction types available for the current title
			// this might happen if an extension alters the available types
			$out->setPageTitle( $context->msg(
				'protect-norestrictiontypes-title',
				$title->getPrefixedText()
			) );
			$out->addWikiTextAsInterface(
				$context->msg( 'protect-norestrictiontypes-text' )->plain()
			);
			return;
		}

		# Show an appropriate message if the user isn't allowed or able to change
		# the protection settings at this time
		if ( $this->disabled ) {
			$out->setPageTitle(
				$context->msg( 'protect-title-notallowed', $title->getPrefixedText() )
			);
			$out->addWikiTextAsInterface(
				$out->formatPermissionsErrorMessage( $this->permErrors, 'userprotect' )
			);
		} else {
			$out->setPageTitle( $context->msg( 'protect-title', $title->getPrefixedText() ) );
			$out->addWikiMsg( 'protect-text', wfEscapeWikiText( $title->getPrefixedText() ) );
		}

		$applicableLegends = [];
		$applicableTypes = UserProtectPermissionManager::getApplicableTypes( $title->exists() );
		array_unshift( $applicableTypes, 'all' );
		foreach ( $applicableTypes as $type ) {
			if ( $type === 'all' ) {
				$msg = $context->msg( 'userprotect-label-all-rights' );
			} else {
				$msg = $context->msg( "right-$type" );
			}
			$applicableLegends[$type] = $msg->exists() ? $msg->text() : $type;
		}
		$out->addJsConfigVars( [
			'extUserProtectConfig' => [
				'token' => $context->getUser()->getEditToken( [ 'userprotect', $title->getPrefixedDBkey() ] ),
				'disabled' => $this->disabled,
				'rights' => $this->rights,
				'applicableTypes' => $applicableTypes,
				'applicableLegends' => $applicableLegends,
			],
		] );
		$out->addModules( 'ext.UserProtect.form' );

		$out->enableOOUI();
		$progressBar = new ProgressBarWidget( [
			'progress' => false,
			'classes' => [ 'userprotect-remove-when-ready' ]
		] );
		$out->addHTML( $progressBar );
	}

	/**
	 * Returns existing user names with user id
	 * as an array [ 'user name' => id ]
	 *
	 * @param array $userNames
	 * @return array
	 */
	private static function getUsersByName( array $userNames ): array {
		if ( !$userNames ) {
			return [];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'user',
			[ 'user_id', 'user_name' ],
			[ 'user_name' => array_unique( $userNames ) ],
			__METHOD__
		);

		$users = [];
		foreach ( $res as $row ) {
			$users[$row->user_name] = (int)$row->user_id;
		}
		return $users;
	}

	/**
	 * Returns submitted user names from UsersMultiselectWidget
	 *
	 * @param WebRequest $request
	 * @param string $name
	 * @return array
	 */
	private static function getUserNames( WebRequest $request, string $name ): array {
		$value = $request->getVal( $name );
		return $value ? explode( "\r\n", $value ) : [];
	}

}
