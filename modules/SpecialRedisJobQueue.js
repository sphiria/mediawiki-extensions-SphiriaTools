( function ( $, mw ) {
	'use strict';

	$( function () {
		// Clear search input on page load
		$( '#jobqueue-search-input' ).val('');

		// Handler for the show/hide data buttons
		$( '.jobqueue-data-toggle' ).on( 'click', function () {
			var $button = $( this );
			var $dataDiv = $button.next( '.jobqueue-data-content' );
			var showText = mw.msg( 'jobqueue-showhide-show' );
			var hideText = mw.msg( 'jobqueue-showhide-hide' );

			if ( $dataDiv.is( ':hidden' ) ) {
				$dataDiv.slideDown();
				$button.text( hideText );
			} else {
				$dataDiv.slideUp();
				$button.text( showText );
			}
		} );

		// Handler for the confirm delete checkbox
		$( '#confirm-delete' ).on( 'change', function () {
			$( '#delete-jobs-button' ).prop( 'disabled', !this.checked );
		} );

		// Debounce function
		function debounce( func, wait ) {
			var timeout;
			return function executedFunction() {
				var context = this;
				var args = arguments;
				var later = function() {
					timeout = null;
					func.apply( context, args );
				};
				clearTimeout( timeout );
				timeout = setTimeout( later, wait );
			};
		}

		// Debounced handler for the job data search input
		var handleSearch = debounce( function() {
			var $input = $( '#jobqueue-search-input' );
			var searchTerm = $input.val().trim();
			var searchTermLower = searchTerm.toLowerCase();
			var $tableRows = $( '.jobqueue-detail-table tbody tr' );
			var hideText = mw.msg( 'jobqueue-showhide-hide' );

			var highlightRegex = null;
			if ( searchTerm !== '' ) {
				var escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				highlightRegex = new RegExp( '(' + escapedTerm + ')', 'gi' );
			}

			$tableRows.each( function () {
				var $row = $( this );
				var $dataTd = $row.find('td:last-child');
				var $dataDiv = $dataTd.find( '.jobqueue-data-content' );
				var $dataPre = $dataDiv.find( 'pre' );
				var $toggleButton = $dataTd.find( '.jobqueue-data-toggle' );
				var originalText = $dataPre.data( 'original-text' );

				if ( originalText === undefined && $dataPre.length > 0 ) {
					originalText = $dataPre.text();
					$dataPre.data( 'original-text', originalText );
				}

				var jobDataText = (originalText || '').toLowerCase();
				var match = ( searchTermLower === '' || jobDataText.indexOf( searchTermLower ) > -1 );

				if ( match ) {
					$row.show();

					if ( searchTerm !== '' && $dataDiv.is( ':hidden' ) ) {
						$dataDiv.slideDown();
						$toggleButton.text( hideText );
					}

					if ( searchTerm !== '' && highlightRegex && originalText ) {
						var escapedOriginal = $( '<div/>' ).text( originalText ).html(); 
						var highlightedHtml = escapedOriginal.replace( highlightRegex, '<mark class="jobqueue-search-highlight">$1</mark>' );
						$dataPre.html( highlightedHtml );
					} else if ( originalText ) {
						$dataPre.text( originalText );
					}
				} else {
					$row.hide();
					if ( originalText ) {
						$dataPre.text( originalText );
					}
				}
			} );
		}, 250 ); // 250ms delay

		// Attach the debounced handler
		$( '#jobqueue-search-input' ).on( 'keyup input', handleSearch );

	} );

}( jQuery, mediaWiki ) ); 