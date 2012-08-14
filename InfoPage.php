<?php
/**
 * Implements the "info page" (Wx/xx pages)

'missing' showMissingWiki()
    A [Project] in this language does not yet exist.
'incubator' showIncubatingWiki()
    'open': This is a new Incubator wiki that is not yet verified by the language committee.
    'eligible': This Incubator wiki has been marked as eligible by the language committee.
    'imported': This Incubator wiki has been imported from xyz.wikiproject.org after that wiki was closed.
    'approved': This Incubator wiki has been approved by the language committee and will soon be created.
'existing' showExistingWiki()
    'created': This project has been approved by the language committee and is now available at xyz.wikiproject.org.
    'beforeincubator': This project was created before Wikimedia Incubator started and is available at xyz.wikiproject.org.

 * @file
 * @ingroup Extensions
 * @author Robin Pepermans (SPQRobin)
 */

class InfoPage {

	/**
	 * @param $title Title
	 * @param $prefixdata
	 */
	public function __construct( $title, $prefixdata ) {
		global $wmincProjects, $wmincSisterProjects, $wgLang;
		$this->mTitle = $title;
		$this->mPrefix = $prefixdata['prefix'];
		$this->mLangCode = $prefixdata['lang'];
		$this->mProjectCode = $prefixdata['project'];
		$allProjects = array_merge( $wmincProjects, $wmincSisterProjects );
		$this->mProjectName = isset( $allProjects[$this->mProjectCode] ) ?
			$allProjects[$this->mProjectCode] : '';
		$this->mPortal = IncubatorTest::getSubdomain( 'www', $this->mProjectCode );
		$this->mIsSister = array_key_exists( $this->mProjectCode, $wmincSisterProjects );
		$this->mDBStatus = '';
		$this->mSubStatus = '';
		$this->mThisLangData = array( 'type' => 'valid' ); # For later code check feature
		$this->mLangNames = Language::fetchLanguageNames( $wgLang->getCode(), 'all' );
		$this->mLangName = isset( $this->mLangNames[$this->mLangCode] ) ?
			$this->mLangNames[$this->mLangCode] : null;
		$titleParam = $this->mLangName ? $this->mLangName : '"' . $this->mLangCode . '"'; # Name, else code
		$this->mFormatTitle = wfMessage( 'wminc-infopage-title-' . $this->mProjectCode, $titleParam )->escaped();
		if( !$this->mLangName ) {
			# Unknown language, add short note to title
			$this->mFormatTitle .= ' ' . wfMessage( 'wminc-unknownlang', $this->mLangCode )->escaped();
		}
		return;
	}

	/**
	 * Small convenience function to display a (clickable) logo
	 * @param $project String: Project code
	 * @param $clickable Boolean
	 * @param $params Array
	 * @param $url String
	 * @param $lang String
	 * @param $mul Boolean
	 * @return String
	 */
	public function makeLogo( $project, $clickable = true, $params = array(), $url = null, $lang = null, $mul = false ) {
		$lang = $lang ? $lang : $this->mLangCode;
		if( !$mul ) { // for non-multilingual wikis
			$getDbStatus = IncubatorTest::getDBState(
				array( 'error' => null, 'lang' => $lang, 'project' => $project )
			);
			$lang = $getDbStatus == 'missing' ? 'en' : $lang;
			$params['title'] = IncubatorTest::getProject( $project, true, true );
		}
		$params['src'] = IncubatorTest::getConf( 'wgLogo', $lang, $project );
		$params['alt'] = "$project ($lang)";
		$img = Html::element( 'img', $params );
		if( $clickable ) {
			if( $url === null ) {
				$url = IncubatorTest::getSubdomain( 'www', $project );
			}
			return Html::rawElement( 'a', array( 'href' => $url ), $img );
		}
		return $img;
	}

	/**
	 * @return String: list of clickable logos of other projects
	 *					(Wikipedia, Wiktionary, ...)
	 */
	public function listOtherProjects() {
		global $wmincProjects, $wmincSisterProjects;
		$otherProjects = $wmincProjects + $wmincSisterProjects;
		unset( $otherProjects[$this->mProjectCode] );
		$listOtherProjects = array();
		foreach( $otherProjects as $code => $name ) {
			$listOtherProjects[$code] = '<li>' . $this->makeLogo( $code, true,
				array( 'width' => 75 ), IncubatorTest::getSubdomain( $this->mLangCode, $code ) ) . '</li>';
		}
		return '<ul class="wminc-infopage-otherprojects">' .
			implode( '', $listOtherProjects ) . '</ul>';
	}

	/**
	 * @return String: list of clickable logos of multilingual projects
	 *					(Meta, Commons, ...)
	 */
	public function listMultilingualProjects() {
		global $wmincMultilingualProjects, $wmincProjects;
		if( !is_array( $wmincMultilingualProjects ) ) { return ''; }
		$list = array();
		foreach( $wmincMultilingualProjects as $key => $name ) {
			# multilingual projects are listed under wikipedia
			$fakeProject = key( $wmincProjects );
			$url = IncubatorTest::getSubdomain( $key, $fakeProject );
			$list[$url] = '<li>' . $this->makeLogo( $fakeProject, true,
				array( 'width' => 75 ), $url, $key, true ) . '</li>';
		}
		return '<ul class="wminc-infopage-multilingualprojects">' .
			implode( '', $list ) . '</ul>';
	}

	/**
	 * @return String: "Welcome to Incubator" message
	 */
	public function showWelcome() {
		return Html::rawElement( 'div', array( 'class' => 'wminc-infopage-welcome' ),
			wfMessage( 'wminc-infopage-welcome' )->parseAsBlock() );
	}

	/**
	 * @return String: the core HTML for the info page
	 */
	public function StandardInfoPage( $beforetitle, $aftertitle, $content ) {
		global $wgLang;
		return Html::rawElement( 'div', array( 'class' => 'wminc-infopage plainlinks',
			'lang' => $wgLang->getCode(), 'dir' => $wgLang->getDir() ),
			$beforetitle .
			Html::rawElement( 'div', array( 'class' => 'wminc-infopage-logo' ),
				$this->makeLogo( $this->mProjectCode )
			) .
			Html::rawElement( 'div', array( 'class' => 'wminc-infopage-title' ),
				$this->mFormatTitle . $aftertitle ) .
			$content );
	}

	/**
	 * @return String
	 */
	public function showMissingWiki() {
		global $wgRequest;
		$link = SpecialPage::getTitleFor( 'IncubatorFirstSteps' );
		$query = array( 'testwiki' => $this->mPrefix,
			'uselang' => $wgRequest->getVal( 'uselang' ) );
		$steps = $link->getFullUrl( $query );

		$content = Html::rawElement( 'div',
			array( 'class' => 'wminc-infopage-status' ),
			wfMessage( 'wminc-infopage-missingwiki-text',
			$this->mProjectName, $this->mLangName )->parseAsBlock()
		) .
		Html::rawElement( 'ul', array( 'class' => 'wminc-infopage-options' ),
			Html::rawElement( 'li', null,
				wfMessage( $this->mIsSister ? 'wminc-infopage-option-startsister' : 'wminc-infopage-option-startwiki',
					$this->mProjectName, $this->mPortal, $steps )->parse() ) .
			Html::rawElement( 'li', null,
				wfMessage( 'wminc-infopage-option-languages-existing',
					$this->mProjectName )->parse() ) .
			Html::rawElement( 'li', null,
				wfMessage( 'wminc-infopage-option-sisterprojects-existing' )->parse() .
				$this->listOtherProjects() ) .
			Html::rawElement( 'li', null,
				wfMessage( 'wminc-infopage-option-multilingual' )->parse() .
				$this->listMultilingualProjects() )
		);
		return $this->StandardInfoPage( $this->showWelcome(), '', $content );
	}

	/**
	 * @return String
	 */
	public function showIncubatingWiki() {
		global $wgLang;
		$substatus = $this->mSubStatus;
		if( $substatus == 'imported' && $this->mIsSister ) {
			$substatus = 'closedsister';
		}
		$portalLink = Linker::makeExternalLink( $this->mPortal, $this->mProjectName );
		$mainpage = isset( $this->mOptions['mainpage'] ) ?
			Title::newFromText( $this->mPrefix . '/' . $this->mOptions['mainpage'] ) :
			IncubatorTest::getMainPage( $this->mLangCode, $this->mPrefix );
		if( $this->mThisLangData['type'] != 'invalid' ) {
			$gotoLink = Linker::link(
				$mainpage,
				wfMessage( 'wminc-infopage-enter' )->escaped() );
			$gotoMainPage = Html::rawElement( 'span',
				array( 'class' => 'wminc-infopage-entertest' ),
				$wgLang->getArrow() . ' ' . ( $this->mIsSister ? $portalLink : $gotoLink ) );
		}
		$subdomain = IncubatorTest::getSubdomain( $this->mLangCode, $this->mProjectCode );
		$subdomainLink = IncubatorTest::makeExternalLinkText( $subdomain, true );
		$content = Html::rawElement( 'div', array( 'class' => 'wminc-infopage-status' ),
			wfMessage( 'wminc-infopage-status-' . $substatus )->rawParams( $subdomainLink, $portalLink )->parseAsBlock() );
		if( $this->mSubStatus != 'approved' && $this->mThisLangData['type'] != 'invalid' ) {
			$content .= Html::element( 'div',
				array( 'class' => 'wminc-infopage-contribute' ),
				wfMessage( 'wminc-infopage-contribute' )->plain() );
		}
		$content .= Html::rawElement( 'div', array( 'class' => 'wminc-infopage-links' ),
			# custom links and other stuff
			wfMessage( 'wminc-infopage-links',
				$this->mSubStatus, $this->mPrefix,
				$this->mProjectCode, $this->mProjectName,
				$this->mLangCode, $this->mLangName
			)->inContentLanguage()->parse()
		);
		$content .= Html::rawElement( 'ul', array( 'class' => 'wminc-infopage-options' ),
			Html::rawElement( 'li', null, wfMessage( 'wminc-infopage-option-sisterprojects-other' )->parseAsBlock() .
			$this->listOtherProjects() ) );
		return $this->StandardInfoPage( '', $gotoMainPage, $content );
	}

	/**
	 * @return String
	 */
	public function showExistingWiki() {
		global $wgLang, $wmincSisterProjects;
		$created = isset( $this->mCreated ) ? $this->mCreated : ''; # for future use
		$bug = isset( $this->mBug ) ? $this->mBug : ''; # for future use
		$subdomain = IncubatorTest::getSubdomain( $this->mLangCode, $this->mProjectCode );
		$subdomainLink = IncubatorTest::makeExternalLinkText( $subdomain, true );
		if( $this->mThisLangData['type'] != 'invalid' ) {
			$gotoSubdomain = Html::rawElement( 'span',
				array( 'class' => 'wminc-infopage-entertest' ),
				$wgLang->getArrow() . ' ' . $subdomainLink );
		}
		$msgname = 'wminc-infopage-status-' . $this->mSubStatus; // wminc-infopage-status-beforeincubator
		if( $this->mSubStatus === 'beforeincubator' && isset( $wmincSisterProjects[$this->mProjectCode] ) ) {
			$msgname = 'wminc-infopage-status-beforeincubator-sister';
		}
		$content = Html::rawElement( 'div',
			array( 'class' => 'wminc-infopage-status' ),
			wfMessage( $msgname )->rawParams( $subdomainLink )->parseAsBlock()
		) . Html::rawElement( 'ul', array( 'class' => 'wminc-infopage-options' ),
			Html::rawElement( 'li', null, wfMessage( 'wminc-infopage-option-sisterprojects-other' )->parseAsBlock() .
				$this->listOtherProjects() ) .
			Html::rawElement( 'li', null, wfMessage( 'wminc-infopage-option-multilingual' )->parseAsBlock() .
				$this->listMultilingualProjects() )
		);
		return $this->StandardInfoPage( $this->showWelcome(), $gotoSubdomain, $content );
	}
}
