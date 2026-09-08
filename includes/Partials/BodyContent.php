<?php
/**
 * Citizen - A responsive skin developed for the Star Citizen Wiki
 *
 * This file is part of Citizen.
 *
 * Citizen is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Citizen is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Citizen.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup Skins
 */

declare( strict_types=1 );

namespace MediaWiki\Skins\Citizen\Partials;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXpath;
use HtmlFormatter\HtmlFormatter;
use MediaWiki\MediaWikiServices;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Services\NoSuchServiceException;

final class BodyContent extends Partial {

	/**
	 * The code below is largely based on the extension MobileFrontend
	 * All credits go to the author and contributors of the project
	 */

	/**
	 * Class name for collapsible section wrappers
	 */
	public const STYLE_COLLAPSIBLE_SECTION_CLASS = 'section-collapsible';
	public const STYLE_COLLAPSIBLE_SECTION_COLLAPSED_CLASS = 'section-collapsible--collapsed';
	public const STYLE_SECTION_HEADING_COLLAPSED_CLASS = 'section-heading--collapsed';

	/**
	 * List of tags that could be considered as section headers.
	 * @var array
	 */
	private $topHeadingTags = [ "h1", "h2", "h3", "h4", "h5", "h6" ];

	/**
	 * Helper function to decide if the page should be formatted
	 *
	 * @param Title $title
	 * @return string
	 */
	private function shouldFormatPage( $title ) {
		try {
			$mfCxt = MediaWikiServices::getInstance()->getService( 'MobileFrontend.Context' );
			// Check if page is in mobile view and let MF do the formatting
			return !$mfCxt->shouldDisplayMobileView();
		} catch ( NoSuchServiceException $ex ) {
			// MobileFrontend not installed. Don't do anything
		}

		return $this->getConfigValue( 'CitizenEnableCollapsibleSections' ) === true &&
			!$title->isMainPage() &&
			$title->isContentPage();
	}

	/**
	 * Rebuild the body content
	 *
	 * @param string $bodyContent HTML of the body content from core
	 * @return string html
	 */
	public function decorateBodyContent( $bodyContent ) {
		$title = $this->title;

		// Return the page if title is null
		if ( $title === null ) {
			return $bodyContent;
		}

		// Make section and sanitize the output
		if ( $this->shouldFormatPage( $title ) ) {
			$formatter = new HtmlFormatter( $bodyContent );
			$doc = $formatter->getDoc();
			// Make top level sections
			$this->makeSections( $doc, $this->getTopHeadings( $doc ) );
			$formatter->filterContent();
			$bodyContent = $formatter->getText();
		}

		return $bodyContent;
	}

	/**
	 * @param DOMNode|null $node
	 * @return string|false Heading tag name if the node is a heading
	 */
	private function getHeadingName( $node ) {
		if ( !( $node instanceof DOMElement ) ) {
			return false;
		}
		// We accept both kinds of nodes that can be returned by getTopHeadings():
		// a `<h1>` to `<h6>` node, or a `<div class="mw-heading">` node wrapping it.
		// In the future `<div class="mw-heading">` will be required (T13555).
		if ( DOMCompat::getClassList( $node )->contains( 'mw-heading' ) ) {
			$node = DOMCompat::querySelector( $node, implode( ',', $this->topHeadingTags ) );
			if ( !( $node instanceof DOMElement ) ) {
				return false;
			}
		}
		return $node->tagName;
	}

	/**
	 * Actually splits splits the body of the document into sections
	 *
	 * @param DOMDocument $doc representing the HTML of the body content. In the HTML the sections
	 *  should not be wrapped.
	 * @param DOMElement[] $headingWrappers The headings (or wrappers) returned by getTopHeadings():
	 *  `<h1>` to `<h6>` nodes, or `<div class="mw-heading">` nodes wrapping them.
	 *  In the future `<div class="mw-heading">` will be required (T13555).
	 * @return DOMDocument
	 */
	private function makeSections( DOMDocument $doc, array $headingWrappers ) {
		$xpath = new DOMXpath( $doc );
		// Search for slot-wrappers (on pages with additional slots beside 'main')
		// see: https://github.com/OpenSemanticLab/mediawiki/blob/
		// 72bd0b45bbe45b77f919ebe493dc21587f1e7367/includes/Revision/RevisionRenderer.php#L276
		$containers = $xpath->query( '//div[@class="mw-slot-wrapper"]' );

		// Fall back to parent container (on pages with only slot 'main' populated)
		if ( !$containers->length || $containers->item( 0 ) === null ) {
			$containers = $xpath->query( '//div[@class="mw-parser-output"][1]' );
			// Return if no parser output is found
			if ( !$containers->length || $containers->item( 0 ) === null ) {
				return $doc;
			}
		}

		$firstHeading = reset( $headingWrappers );
		$firstHeadingName = $this->getHeadingName( $firstHeading );
		// get the initial collapsed state of the current heading
		$collapsed = false;
		if ( $firstHeading ) {
			$headingClassName = $firstHeading->hasAttribute( 'class' ) ? $firstHeading->getAttribute( 'class' ) : '';
			$collapsed = strpos( $headingClassName, self::STYLE_SECTION_HEADING_COLLAPSED_CLASS ) !== false;
		}
		$sectionNumber = 0;
		// The pre-heading section (section-collapsible-0) is emitted exactly once globally.
		// Citizen's sections.js uses sections[i+1] to pair headings[i] with its body, so it
		// requires exactly one anchor section at index 0. With multiple slot-wrappers, only
		// the first container that contains (or precedes) the first heading may emit it.
		$prependingSectionEmitted = false;

		foreach ( $containers as $container ) {
			// Skip containers that have no top-level headings (e.g. mw-slot-wrapper-header
			// holding an info box); their content stays as-is so it neither inherits the
			// collapsed state of another slot's first heading nor throws off the
			// heading-to-section index mapping used by Citizen's sections.js.
			$containerHasHeading = false;
			for ( $c = $container->firstChild; $c; $c = $c->nextSibling ) {
				if ( $firstHeadingName && $this->getHeadingName( $c ) === $firstHeadingName ) {
					$containerHasHeading = true;
					break;
				}
			}
			if ( !$containerHasHeading ) {
				continue;
			}

			// sectionBody is created on demand: when we hit the first non-heading node
			// (pre-heading content) or when we cross a heading. This avoids emitting
			// empty pre-heading anchor sections for every slot-wrapper.
			$sectionBody = null;
			$containerChild = $container->firstChild;

			while ( $containerChild ) {
				$node = $containerChild;
				$containerChild = $containerChild->nextSibling;

				if ( $firstHeadingName && $this->getHeadingName( $node ) === $firstHeadingName ) {
					/** @phan-suppress-next-line PhanTypeMismatchArgument DOMNode vs. DOMElement */
					$this->prepareHeading( $doc, $node, $sectionNumber + 1 );
					if ( $sectionBody !== null ) {
						// Insert the sectionBody accumulated so far before this heading.
						$container->insertBefore( $sectionBody, $node );
						$prependingSectionEmitted = true;
					} elseif ( !$prependingSectionEmitted ) {
						// Emit the single global empty pre-heading anchor (section-collapsible-0)
						// so Citizen's sections.js index mapping stays consistent.
						// Never collapsed: see the note at the pre-heading content below.
						$emptyPre = $this->createSectionBodyElement( $doc, $sectionNumber, $sectionNumber > 0 && $collapsed );
						$container->insertBefore( $emptyPre, $node );
						$prependingSectionEmitted = true;
					}

					++$sectionNumber;
					$headingClassName = $node->hasAttribute( 'class' ) ? $node->getAttribute( 'class' ) : '';
					$collapsed = strpos( $headingClassName, self::STYLE_SECTION_HEADING_COLLAPSED_CLASS ) !== false;
					$sectionBody = $this->createSectionBodyElement( $doc, $sectionNumber, $collapsed );

					continue;
				}

				if ( $sectionBody === null ) {
					if ( $sectionNumber > 0 ) {
						// Content before the first heading of a later slot-wrapper, such as
						// the blank lines a footer template starts with. It has no heading of
						// its own, and sections.js pairs headings with sections by position,
						// so wrapping it would shift every heading onto the body of the one
						// before it and leave the last body with no heading at all. It stays
						// where it is instead.
						continue;
					}

					// Section 0 is never collapsed. $collapsed holds the first heading's
					// state, which is not this section's: section 0 has no heading of its
					// own, so nothing renders a toggle for it and collapsing it would make
					// its content unreachable.
					$sectionBody = $this->createSectionBodyElement( $doc, $sectionNumber, false );
				}
				$sectionBody->appendChild( $node );
			}

			// Append the last section body (may be null if the container ended immediately
			// after a heading with no trailing content).
			if ( $sectionBody !== null ) {
				$container->appendChild( $sectionBody );
			}
		}

		// Mark subheadings
		$this->markSubHeadings( $this->getSubHeadings( $doc ) );

		return $doc;
	}

	/**
	 * Prepare section headings, add required classes
	 *
	 * @param DOMDocument $doc
	 * @param DOMElement $heading
	 * @param int $sectionNumber
	 */
	private function prepareHeading( DOMDocument $doc, DOMElement $heading, $sectionNumber ) {
		$className = $heading->hasAttribute( 'class' ) ? $heading->getAttribute( 'class' ) . ' ' : '';
		$heading->setAttribute( 'class', $className . 'section-heading' );

		// prepend indicator - this avoids a reflow by creating a placeholder for a toggling indicator
		$indicator = $doc->createElement( 'span' );
		$indicator->setAttribute( 'class', 'section-indicator citizen-ui-icon mw-ui-icon-wikimedia-collapse' );
		$heading->insertBefore( $indicator, $heading->firstChild );
	}

	/**
	 * Creates a Section body element
	 *
	 * @param DOMDocument $doc
	 * @param int $sectionNumber
	 *
	 * @return DOMElement
	 */
	private function createSectionBodyElement( DOMDocument $doc, $sectionNumber, $collapsed = false ) {
		$sectionBody = $doc->createElement( 'section' );
		$classList = self::STYLE_COLLAPSIBLE_SECTION_CLASS;
		if ( $collapsed ) { $classList .= " " . self::STYLE_COLLAPSIBLE_SECTION_COLLAPSED_CLASS;
		}
		$sectionBody->setAttribute( 'class', $classList );
		$sectionBody->setAttribute( 'id', 'section-collapsible-' . $sectionNumber );

		return $sectionBody;
	}

	/**
	 * Gets top headings in the document.
	 *
	 * @param DOMDocument $doc
	 * @return array An array first is the highest rank headings
	 */
	private function getTopHeadings( DOMDocument $doc ): array {
		$headings = [];

		foreach ( $this->topHeadingTags as $tagName ) {
			$allTags = DOMCompat::querySelectorAll( $doc, $tagName );

			foreach ( $allTags as $el ) {
				$parent = $el->parentNode;
				if ( !( $parent instanceof DOMElement ) ) {
					continue;
				}
				// Use the `<div class="mw-heading">` wrapper if it is present. When they are required
				// (T13555), the querySelectorAll() above can use the class and this can be removed.
				if ( DOMCompat::getClassList( $parent )->contains( 'mw-heading' ) ) {
					$el = $parent;
				}
				// This check can be removed too when we require the wrappers.
				if ( $parent->getAttribute( 'class' ) !== 'toctitle' ) {
					$headings[] = $el;
				}
			}
			if ( $headings ) {
				return $headings;
			}

		}

		return $headings;
	}

	/**
	 * Marks the subheadings for the approiate styles by adding
	 * the <code>section-subheading</code> class to each of them, if it
	 * hasn't already been added.
	 *
	 * @param DOMElement[] $headings Heading elements
	 */
	protected function markSubHeadings( array $headings ) {
		foreach ( $headings as $heading ) {
			$class = $heading->getAttribute( 'class' );
			if ( strpos( $class, 'section-subheading' ) === false ) {
				$heading->setAttribute(
					'class',
					ltrim( $class . ' section-subheading' )
				);
			}
		}
	}

	/**
	 * Gets all subheadings in the document in rank order.
	 *
	 * @param DOMDocument $doc
	 * @return DOMElement[]
	 */
	private function getSubHeadings( DOMDocument $doc ): array {
		$found = false;
		$subheadings = [];
		foreach ( $this->topHeadingTags as $tagName ) {
			$allTags = DOMCompat::querySelectorAll( $doc, $tagName );
			$elements = [];
			foreach ( $allTags as $el ) {
				if ( $el->parentNode->getAttribute( 'class' ) !== 'toctitle' ) {
					$elements[] = $el;
				}
			}

			if ( $elements ) {
				if ( !$found ) {
					$found = true;
				} else {
					$subheadings = array_merge( $subheadings, $elements );
				}
			}
		}

		return $subheadings;
	}
}
