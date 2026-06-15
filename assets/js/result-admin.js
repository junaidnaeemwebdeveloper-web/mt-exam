( function () {
	'use strict';

	if ( 'undefined' === typeof emResultAdmin ) {
		return;
	}

	function updateSubjectLabel() {
		var examSelect = document.getElementById( 'em_result_exam_id' );
		var subjectLabel = document.getElementById( 'em_result_subject_name' );

		if ( ! examSelect || ! subjectLabel || 'SELECT' !== examSelect.tagName ) {
			return;
		}

		var selectedOption = examSelect.options[ examSelect.selectedIndex ];
		var subjectName = selectedOption ? selectedOption.getAttribute( 'data-subject' ) : '';

		subjectLabel.textContent = subjectName || emResultAdmin.selectExamSubjectLabel || 'Select an exam to view its subject.';
	}

	function hasAtLeastOneMark() {
		var markInputs = document.querySelectorAll( '.em-result-mark-input' );

		return Array.prototype.some.call( markInputs, function ( input ) {
			return '' !== input.value.trim();
		} );
	}

	function validateMarkInputs() {
		var markInputs = document.querySelectorAll( '.em-result-mark-input' );

		for ( var i = 0; i < markInputs.length; i++ ) {
			var value = markInputs[ i ].value.trim();

			if ( '' === value ) {
				continue;
			}

			var mark = parseFloat( value );

			if ( isNaN( mark ) || mark < 0 || mark > emResultAdmin.maxMark ) {
				window.alert( emResultAdmin.invalidMarkMessage );
				markInputs[ i ].focus();
				return false;
			}
		}

		return true;
	}

	function validateResultForm() {
		var examSelect = document.getElementById( 'em_result_exam_id' );

		if ( examSelect && 'SELECT' === examSelect.tagName && ! examSelect.value ) {
			window.alert( emResultAdmin.missingExamMessage );
			examSelect.focus();
			return false;
		}

		if ( ! hasAtLeastOneMark() ) {
			window.alert( emResultAdmin.missingMarksMessage );
			return false;
		}

		return validateMarkInputs();
	}

	function bindValidation() {
		var examSelect = document.getElementById( 'em_result_exam_id' );

		if ( examSelect && 'SELECT' === examSelect.tagName ) {
			examSelect.addEventListener( 'change', updateSubjectLabel );
			updateSubjectLabel();
		}

		var postForm = document.getElementById( 'post' );

		if ( postForm ) {
			postForm.addEventListener( 'submit', function ( event ) {
				if ( ! validateResultForm() ) {
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
				if ( ! validateResultForm() ) {
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
