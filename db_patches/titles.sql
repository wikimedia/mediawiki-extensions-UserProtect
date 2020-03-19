BEGIN;

-- Protected titles - nonexistent pages that have been protected
CREATE TABLE /*_*/user_protect_titles (
	upt_namespace int NOT NULL,
	upt_title varchar(255) binary NOT NULL,
	upt_user int unsigned NOT NULL,
	upt_type varbinary(60) NOT NULL,
	upt_added bool NOT NULL,
	upt_timestamp varbinary(14) NOT NULL default '',
	PRIMARY KEY upr_page_user_type (upt_namespace, upt_title, upt_user, upt_type)
)/*$wgDBTableOptions*/;

COMMIT;
