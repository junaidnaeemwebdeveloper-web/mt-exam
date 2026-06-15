( function () {
	'use strict';

	if ( 'undefined' === typeof emTermAdmin ) {
		return;
	}

	function validateDates( startInput, endInput ) {
		if ( ! startInput.value || ! endInput.value ) {
			window.alert( emTermAdmin.missingDateMessage );
			return false;
		}

		if ( endInput.value < startInput.value ) {
			window.alert( emTermAdmin.invalidDateMessage );
			return false;
		}

		return true;
	}

	function bindFormValidation( formId ) {
		var form = document.getElementById( formId );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			var startInput = form.querySelector( '#em_start_date' );
			var endInput = form.querySelector( '#em_end_date' );

			if ( ! startInput || ! endInput ) {
				return;
			}

			if ( ! validateDates( startInput, endInput ) ) {
				event.preventDefault();
			}
		} );
	}

	bindFormValidation( 'addtag' );
	bindFormValidation( 'edittag' );
}() );
