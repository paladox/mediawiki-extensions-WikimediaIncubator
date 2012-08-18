<?php
/**
 * Special page to go to the Main Page, depending on your test wiki.
 * Goes to the default Main Page if the test wiki preference
 * is set to None/All or Incubator.
 *
 * @file
 * @ingroup SpecialPage
 * @author Robin Pepermans (SPQRobin)
 */

class SpecialMyMainPage extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'MyMainPage' );
	}

	public function execute( $par ) {
		$title = '';
		$params = array();

		# Build Wx/xyz?goto=mainpage[&testwiki=]
		if ( IncubatorTest::isContentProject() ) {
			$title = Title::newFromText( IncubatorTest::displayPrefix() );
			if( $this->getRequest()->getVal( 'goto' ) != 'infopage' ) {
				$params['goto'] = 'mainpage';
			}
			$url = IncubatorTest::getUrlParam();
			if( $url ) {
				$params['testwiki'] = $url['prefix'];
			}
		}

		# Go to the main page if given invalid title, or if it's not a content project
		if ( !$title ) {
			$title = Title::newMainPage();
		}

		$this->getOutput()->redirect( $title->getLocalURL( $params ) );
	}
}
