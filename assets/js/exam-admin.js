( function () {
	'use strict';

	if ( 'undefined' === typeof emExamAdmin ) {
		return;
	}

	function validateExamFields() {
		var startInput = document.getElementById( 'em_exam_start_datetime' );
		var endInput = document.getElementById( 'em_exam_end_datetime' );
		var subjectInput = document.getElementById( 'em_exam_subject_id' );
		var termInput = document.getElementById( 'em_exam_term_id' );

		if ( ! startInput || ! endInput || ! subjectInput || ! termInput ) {
			return true;
		}

		if ( ! startInput.value || ! endInput.value || ! subjectInput.value || ! termInput.value ) {
			window.alert( emExamAdmin.missingFieldMessage );
			startInput.focus();
			return false;
		}

		if ( endInput.value < startInput.value ) {
			window.alert( emExamAdmin.invalidDateTimeMessage );
			endInput.focus();
			return false;
		}

		return true;
	}

	function bindValidation() {
		var postForm = document.getElementById( 'post' );

		if ( postForm ) {
			postForm.addEventListener( 'submit', function ( event ) {
				if ( ! validateExamFields() ) {
					event.preventDefault();
					event.stopPropagation();
				}
			} );
		}

		[ 'publish', 'save-post' ].forEach( function ( buttonId ) {
			var button = document.getElementById( buttonId );

			if ( ! button ) {
				return;
			}

			button.addEventListener( 'click', function ( event ) {
				if ( ! validateExamFields() ) {
					event.preventDefault();
					event.stopImmediatePropagation();
					return false;
				}
			}, true );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindValidation );
	} else {
		bindValidation();
	}
}() );
