<?php

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserIdentity;

class UserProtectPermissionManager extends PermissionManager {
	/**
	 * List of possible permissions for non-existent pages
	 *
	 * @var bool[]
	 */
	private const CREATE_RIGHTS = [
		'createpage' => true,
		'createtalk' => true,
	];

	/** @var int */
	private const CACHE_TITLES = 0;

	/** @var int */
	private const CACHE_RIGHTS = 1;

	/** @var array */
	private static $cache = [];

	/** @var LinkTarget|null */
	private $userProtectPage;

	/** @var array|null */
	private $removedRights;

	/**
	 * @param string $action
	 * @param User $user
	 * @param LinkTarget $page
	 * @param string $rigor
	 * @return bool
	 */
	public function userCan( $action, User $user, LinkTarget $page, $rigor = self::RIGOR_SECURE ): bool {
		$this->userProtectPage = $page;
		$return = parent::userCan( $action, $user, $page, $rigor );
		$this->userProtectPage = null;
		MWDebug::log( $action . ' ' . ( $return ? 'true' : 'false' ) );
		wfDebug( __METHOD__ . ': ' . $action . ' ' . ( $return ? 'true' : 'false' ) );
		return $return;
	}

	/**
	 * @param string $action
	 * @param User $user
	 * @param LinkTarget $page
	 * @param string $rigor
	 * @param array $ignoreErrors
	 * @return array[]
	 */
	public function getPermissionErrors(
		$action, User $user, LinkTarget $page, $rigor = self::RIGOR_SECURE, $ignoreErrors = []
	): array {
		$this->userProtectPage = $page;
		$this->removedRights = [];
		$return = parent::getPermissionErrors( $action, $user, $page, $rigor, $ignoreErrors );
		$this->userProtectPage = null;
		if ( $this->removedRights && in_array( $action, $this->removedRights ) ) {
			foreach ( $return as &$error ) {
				if ( $error[0] === 'badaccess-groups' ) {
					$error = [ 'badaccess-group0' ];
				}
			}
		}
		$this->removedRights = null;
		MWDebug::log( $action . ' ' . var_export( $return, true ) );
		wfDebug( __METHOD__ . ': ' . $action . ' ' . var_export( $return, true ) );
		return $return;
	}

	/**
	 * @param UserIdentity $user
	 * @return array|string[]
	 */
	public function getUserPermissions( UserIdentity $user ): array {
		$permissions = parent::getUserPermissions( $user );

		if ( $this->userProtectPage && $user->getId() ) {
			$title = Title::newFromLinkTarget( $this->userProtectPage );
			wfDebug( __METHOD__ . ': ' . $title->getArticleID() );
			[ $removed, $added ] = self::getRights( $title, $user->getId() );
			if ( $added ) {
				$permissions = array_merge( $permissions, $added );
			}
			if ( $removed ) {
				if ( $this->removedRights !== null ) {
					$this->removedRights = array_intersect( $removed, $permissions );
				}
				$permissions = array_diff( $permissions, $removed );
			}
		} else {
			wfDebug( __METHOD__ . ': ' . wfBacktrace( true ) );
		}

		return $permissions;
	}

	/**
	 * Returns list of removed and added permissions for the title and user
	 * as an array [ 0 => removed, 1 => added ]
	 *
	 * @param Title $title
	 * @param int $userId
	 * @return array
	 */
	private static function getRights( Title $title, int $userId ): array {
		$pageId = $title->getArticleID();
		$dbKey = $title->getDBkey();
		$namespace = $title->getNamespace();

		if ( $pageId && isset( self::$cache[self::CACHE_RIGHTS][$pageId][$userId] ) ) {
			return self::$cache[self::CACHE_RIGHTS][$pageId][$userId];
		} elseif ( !$pageId && isset( self::$cache[self::CACHE_TITLES][$namespace][$dbKey][$userId] ) ) {
			return self::$cache[self::CACHE_TITLES][$namespace][$dbKey][$userId];
		}

		$types = self::getApplicableTypes( (bool)$pageId );
		$types[] = 'all';

		if ( $pageId ) {
			$table = 'user_protect_rights';
			$vars = [
				'type' => 'upr_type',
				'added' => 'upr_added',
			];
			$conds = [
				'upr_page' => $pageId,
				'upr_user' => $userId,
				'upr_type' => $types,
			];
		} else {
			$table = 'user_protect_titles';
			$vars = [
				'type' => 'upt_type',
				'added' => 'upt_added',
			];
			$conds = [
				'upt_namespace' => $namespace,
				'upt_title' => $dbKey,
				'upt_user' => $userId,
				'upt_type' => $types,
			];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( $table, $vars, $conds, __METHOD__ );

		$rights = [ [], [] ];
		foreach ( $res as $row ) {
			$added = (int)$row->added;
			if ( $row->type === 'all' ) {
				$rights[$added] = self::getApplicableTypes( (bool)$pageId );
			} else {
				$rights[$added][] = $row->type;
			}
		}

		if ( $pageId ) {
			self::$cache[self::CACHE_RIGHTS][$pageId][$userId] = $rights;
		} else {
			self::$cache[self::CACHE_TITLES][$namespace][$dbKey][$userId] = $rights;
		}
		return $rights;
	}

	/**
	 * Returns applicable permission types
	 *
	 * @param bool $pageExists
	 * @return array
	 */
	public static function getApplicableTypes( $pageExists ): array {
		static $edit = null,
			$create;

		if ( $edit === null ) {
			$types = RequestContext::getMain()->getConfig()->get( 'UserProtectRestrictionTypes' );
			$filtered = array_filter( $types );
			$createRights = self::CREATE_RIGHTS;
			$edit = array_keys( array_diff_key( $filtered, $createRights ) );
			$createRights['userprotect'] = true;
			$create = array_keys( array_intersect_key( $filtered, $createRights ) );
		}
		return $pageExists ? $edit : $create;
	}
}
