<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;

class UserProtectHooks {

	/**
	 * Alter the structured navigation links in SkinTemplates
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation
	 * @param SkinTemplate $skinTemplate
	 * @param array &$links
	 */
	public static function onSkinTemplateNavigation( SkinTemplate $skinTemplate, array &$links ) {
		$title = $skinTemplate->getTitle();
		if ( !$title ) {
			return;
		}

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$user = $skinTemplate->getUser();
		$ns = $title->getNamespace();
		if ( !( $permissionManager->quickUserCan( 'userprotect', $user, $title ) &&
			$title->getRestrictionTypes() &&
			$permissionManager->getNamespaceRestrictionLevels( $ns, $user ) !== [ '' ] )
		) {
			return;
		}

		$action = $skinTemplate->getRequest()->getVal( 'action', 'view' );
		$class = 'userprotect-menu-item';
		if ( $action === 'userprotect' ) {
			$class .= ' selected';
		}

		$links['actions']['userprotect'] = [
			'class' => $class,
			'text' => $skinTemplate->msg( 'ext-userprotect-tab-text' )->text(),
			'href' => $title->getLocalUrl( [ 'action' => 'userprotect' ] )
		];
		$skinTemplate->getOutput()->addModules( 'ext.UserProtect.navigation' );
	}

	/**
	 * Register UserProtect services
	 * @param MediaWikiServices $container
	 */
	public static function onMediaWikiServices( MediaWikiServices $container ) {
		$container->redefineService(
			'PermissionManager',
			function ( MediaWikiServices $services ) : PermissionManager {
				return new UserProtectPermissionManager(
					new ServiceOptions(
						PermissionManager::CONSTRUCTOR_OPTIONS, $services->getMainConfig()
					),
					$services->getSpecialPageFactory(),
					$services->getRevisionLookup(),
					$services->getNamespaceInfo(),
					$services->getBlockErrorFormatter()
				);
			}
		);
	}

	/**
	 * Occurs after the delete article request has been processed
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param string $reason
	 * @param int $id
	 * @param Content $content
	 * @param LogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public static function onArticleDeleteComplete(
		WikiPage $wikiPage, User $user, string $reason, int $id, Content $content,
		LogEntry $logEntry, int $archivedRevisionCount
	) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'user_protect_rights',
			[
				'upr_page' => $id,
			],
			__METHOD__
		);
	}

	/**
	 * Occurs after a new article is created
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentInsertComplete
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param int $isMinor
	 * @param null $isWatch
	 * @param null $section
	 * @param int $flags
	 * @param Revision $revision
	 */
	public static function onPageContentInsertComplete(
		WikiPage $wikiPage, User $user, Content $content, string $summary,
		int $isMinor, $isWatch, $section, int $flags, Revision $revision
	) {
		$title = $wikiPage->getTitle();
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'user_protect_titles',
			[
				'upt_namespace' => $title->getNamespace(),
				'upt_title' => $title->getDBkey(),
			],
			__METHOD__
		);
	}

	/**
	 * This is attached to the MediaWiki 'LoadExtensionSchemaUpdates' hook.
	 * Fired when MediaWiki is updated to allow extensions to update the database
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'user_protect_rights', __DIR__ . '/../db_patches/rights.sql' );
		$updater->addExtensionTable( 'user_protect_titles', __DIR__ . '/../db_patches/titles.sql' );
	}
}
