/**
 *
 */
( function ( mw, $, OO ) {
	'use strict';

	var config = mw.config.get( 'extUserProtectConfig' ),
		applicableTypes = config.applicableTypes || [],
		applicableLegends = config.applicableLegends || {},
		rights = config.rights || {},
		fieldsetAdd = new OO.ui.FieldsetLayout( { label: mw.msg( 'userprotect-add-rights-legend' ) } ),
		fieldsetRemove = new OO.ui.FieldsetLayout( { label: mw.msg( 'userprotect-remove-rights-legend' ) } ),
		form = new OO.ui.FormLayout( {
			action: mw.Title.newFromText( mw.config.get( 'wgTitle' ), mw.config.get( 'wgNamespaceNumber' ) ).getUrl( { action: 'userprotect' } ),
			method: 'post'
		} ),
		fieldLayoutsAdd = [],
		fieldLayoutsRemove = [],
		token = new OO.ui.HiddenInputWidget( { value: config.token, name: 'userprotect-token' } ),
		submit = new OO.ui.ButtonInputWidget( { label: 'Submit', type: 'submit' } ),
		i, len, i2, len2, type, usersMultiselectAdd, usersMultiselectRemove, fieldAdd, fieldRemove,
		usersAdd, usersRemove, userName;

	for ( i = 0, len = applicableTypes.length; i < len; i++ ) {
		type = applicableTypes[ i ];
		usersMultiselectAdd = new mw.widgets.UsersMultiselectWidget( {
			name: 'add-users-' + type,
			placeholder: mw.msg( 'userprotect-label-users' ),
			input: { autocomplete: false }
		} );
		usersMultiselectRemove = new mw.widgets.UsersMultiselectWidget( {
			name: 'remove-users-' + type,
			placeholder: mw.msg( 'userprotect-label-users' ),
			input: { autocomplete: false }
		} );
		fieldAdd = new OO.ui.FieldLayout( usersMultiselectAdd, {
			tagName: 'fieldset',
			label: applicableLegends[ type ] || type,
			align: 'top',
			classes: [ 'userprotect-filed-add', 'userprotect-type-' + type ]
		} );
		fieldRemove = new OO.ui.FieldLayout( usersMultiselectRemove, {
			tagName: 'fieldset',
			label: applicableLegends[ type ] || type,
			align: 'top',
			classes: [ 'userprotect-filed-remove', 'userprotect-type-' + type ]
		} );
		usersAdd = rights[ 1 ] && rights[ 1 ][ type ];
		usersRemove = rights[ 0 ] && rights[ 0 ][ type ];

		fieldLayoutsAdd.push( fieldAdd );
		fieldLayoutsRemove.push( fieldRemove );

		len2 = usersAdd && usersAdd.length || 0;
		for ( i2 = 0; i2 < len2; i2++ ) {
			userName = usersAdd[ i2 ];
			usersMultiselectAdd.addAllowedValue( userName );
			usersMultiselectAdd.addTag( userName );
		}
		len2 = usersRemove && usersRemove.length || 0;
		for ( i2 = 0; i2 < len2; i2++ ) {
			userName = usersRemove[ i2 ];
			usersMultiselectRemove.addAllowedValue( userName );
			usersMultiselectRemove.addTag( userName );
		}
	}

	fieldsetAdd.addItems( fieldLayoutsAdd );
	fieldsetRemove.addItems( fieldLayoutsRemove );

	form.addItems( [ fieldsetAdd, fieldsetRemove, new OO.ui.FieldLayout( submit ), token ] );

	$( function () {
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.userprotect-remove-when-ready' ).remove();
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '#mw-content-text' ).append( form.$element );
	} );

}( mediaWiki, jQuery, OO ) );
