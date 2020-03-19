BEGIN;

-- Protected pages - existing pages that have been protected
CREATE TABLE /*_*/user_protect_rights (
	upr_page int unsigned NOT NULL,
	upr_user int unsigned NOT NULL,
	upr_type varbinary(60) NOT NULL,
	upr_added bool NOT NULL,
	upr_timestamp varbinary(14) NOT NULL default '',
	PRIMARY KEY upr_page_user_type (upr_page, upr_user, upr_type)
)/*$wgDBTableOptions*/;

COMMIT;
