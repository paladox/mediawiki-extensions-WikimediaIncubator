<?php
/**
 * Main class of the WikimediaIncubator extension.
 * Implement test wiki preference, magic word and prefix check on edit page,
 * and contains general functions for other classes.
 *
 * @file
 * @ingroup Extensions
 * @author Robin Pepermans (SPQRobin)
 */

class WikimediaIncubator {

	/**
	 * Callback for extension registration
	 */
	public static function onRegistration() {
		global $wmincExistingWikis, $wgLocalDatabases;
		global $wgHooks, $wgDisableAuthManager, $wgAuthManagerAutoConfig;

		if ( $wmincExistingWikis === null ) {
			$wmincExistingWikis = $wgLocalDatabases;
		}

		if ( class_exists( MediaWiki\Auth\AuthManager::class ) && empty( $wgDisableAuthManager ) ) {
			$wgAuthManagerAutoConfig['secondaryauth'] += [
				WikimediaIncubatorSecondaryAuthenticationProvider::class => [
					'class' => WikimediaIncubatorSecondaryAuthenticationProvider::class,
					'sort' => 0, // non-UI secondaries should run early
				]
			];
		} else {
			$wgHooks['UserCreateForm'][] = 'AutoTestWiki::onUserCreateForm';
			$wgHooks['AddNewAccount'][] = 'AutoTestWiki::onAddNewAccount';
		}
	}

	/**
	 * Add default preference
	 * @param $defOpt Array
	 * @return true
	 */
	public static function onUserGetDefaultOptions( &$defOpt ) {
		global $wmincPref;

		$defOpt[$wmincPref . '-project'] = 'none';

		return true;
	}

	/**
	 * Add preferences
	 * @param $user User
	 * @param $preferences array
	 * @return true
	 */
	static function onGetPreferences( $user, &$preferences ) {
		global $wmincPref, $wmincProjects, $wmincProjectSite, $wmincLangCodeLength;

		$preferences['language']['help-message'] = 'wminc-prefinfo-language';

		$prefinsert[$wmincPref . '-project'] = [
			'type' => 'select',
			'options' =>
				[ wfMessage( 'wminc-testwiki-none' )->plain() => 'none' ] +
				array_flip( $wmincProjects ) +
				[ wfMessage( 'wminc-testwiki-site' )->plain() => $wmincProjectSite['short'] ],
			'section' => 'personal/i18n',
			'label-message' => 'wminc-testwiki',
			'id' => $wmincPref . '-project',
			'help-message' => 'wminc-prefinfo-project',
		];
		$prefinsert[$wmincPref . '-code'] = [
			'type' => 'text',
			'section' => 'personal/i18n',
			'label-message' => 'wminc-testwiki-code',
			'id' => $wmincPref . '-code',
			'maxlength' => (int)$wmincLangCodeLength,
			'size' => (int)$wmincLangCodeLength,
			'help' => wfMessage( 'wminc-prefinfo-code' )->parse() .
				self::getTestWikiLanguages(),
			'list' => 'wminc-testwiki-codelist',
			'validation-callback' => [ 'WikimediaIncubator', 'validateCodePreference' ],
			'filter-callback' => [ 'WikimediaIncubator', 'filterCodePreference' ],
		];

		$preferences = wfArrayInsertAfter( $preferences, $prefinsert, 'language' );

		return true;
	}

	/**
	 * Add a datalist with languages in MediaWiki,
	 * to suggest common language codes
	 * @return string HTML
	 */
	static function getTestWikiLanguages() {
		$list = Language::fetchLanguageNames( null, 'all' );
		$t = '<datalist id="wminc-testwiki-codelist">' . "\n";
		foreach ( $list as $code => $name ) {
			$t .= Xml::element( 'option', [ 'value' => $code ],
				$code . ' - ' . $name ) . "\n";
		}
		$t .= '</datalist>';
		return $t;
	}

	/**
	 * For the preferences above
	 * @param $input
	 * @param $alldata
	 * @return String or true
	 */
	static function validateCodePreference( $input, $alldata ) {
		global $wmincPref;
		# If the user selected a project that NEEDS a language code,
		# but the user DID NOT enter a valid language code, give an error
		$filteredInput = self::filterCodePreference( $input );
		if ( isset( $alldata[$wmincPref . '-project'] )
			&& self::isContentProject( $alldata[$wmincPref . '-project'] )
			&& !self::validateLanguageCode( $filteredInput )
		) {
			return Xml::element( 'span', [ 'class' => 'error' ],
				wfMessage( 'wminc-prefinfo-error' )->plain() );
		}
		return true;
	}

	/**
	 * For the preferences above
	 * @param $input
	 * @return String or true
	 */
	 static function filterCodePreference( $input ) {
		 return trim( strtolower( $input ) );
	 }

	/**
	 * This validates a given language code.
	 * Only "xx[x]" and "xx[x]-x[xxxxxxxx]" are allowed.
	 * @return Boolean
	 */
	static function validateLanguageCode( $code ) {
		global $wmincLangCodeLength;
		if ( strlen( $code ) > $wmincLangCodeLength ) {
			return false;
		}
		if ( $code == 'be-x-old' ) {
			return true; # one exception... waiting to be renamed to be-tarask
		}
		return (bool)preg_match( '/^[a-z][a-z][a-z]?(-[a-z]+)?$/', $code );
	}

	/**
	 * This validates a full prefix in a given title.
	 * It gives an array with the project and language code, containing
	 * the key 'error' if it is invalid.
	 * Use validatePrefix() if you just want true or false.
	 * Use displayPrefixedTitle() to make a prefix page title.
	 *
	 * @param $input Title|String The title to check (if string, don't include namespace)
	 * @param $onlyInfoPage Bool Whether to validate only the prefix, or
	 * also allow other text within the page title (Wx/xxx vs Wx/xxx/Text)
	 * @param $allowSister Bool Whether to allow sister projects when checking
	 * for the project code.
	 * @return Array with 'error' or 'project', 'lang', 'prefix' and
	 *					optionally 'realtitle'
	 */
	static function analyzePrefix( $input, $onlyInfoPage = false, $allowSister = false ) {
		$data = [ 'error' => null ];
		if ( $input instanceof Title ) {
			global $wmincTestWikiNamespaces;
			$title = $input->getText();
			if ( !in_array( $input->getNamespace(), $wmincTestWikiNamespaces ) ) {
				return [ 'error' => 'notestwikinamespace' ];
			}
			if ( $onlyInfoPage && $input->getNamespace() != NS_MAIN ) {
				# Info pages are only in the main NS
				return [ 'error' => 'nomainnamespace' ];
			}
		} else {
			$title = $input;
		}
		# split title into parts
		$titleparts = explode( '/', $title );
		if ( !is_array( $titleparts ) || !isset( $titleparts[1] ) ) {
			$data['error'] = 'noslash';
		} else {
			# get the x from Wx/...
			$data['project'] = ( isset( $titleparts[0][1] ) ? $titleparts[0][1] : '' );
			$data['lang'] = $titleparts[1]; # language code
			$data['prefix'] = 'W' . $data['project'] . '/' . $data['lang'];
			# check language code
			if ( !self::validateLanguageCode( $data['lang'] ) ) {
				$data['error'] = 'invalidlangcode';
			}
		}
		global $wmincProjects, $wmincSisterProjects;
		$listProjects = array_map( [ __CLASS__, 'preg_quote_slash' ], array_keys( $wmincProjects ) );
		if ( $allowSister && is_array( $wmincSisterProjects ) ) {
			# join the project codes with those of the sister projects
			$listSister = array_map( [ __CLASS__, 'preg_quote_slash' ], array_keys( $wmincSisterProjects ) );
			$listProjects = array_merge( $listProjects, $listSister );
		}
		$listProjects = implode( '|', $listProjects );
		if ( !preg_match( '/^W[' . $listProjects . ']\/[a-z-]+' .
			( $onlyInfoPage ? '$/' : '(\/.+)?$/' ), $title ) ) {
			$data['error'] = 'invalidprefix';
		}
		if ( !$onlyInfoPage && $data['error'] != 'invalidprefix' ) { # there is a Page_title
			$prefixn = strlen( $data['prefix'] . '/' ); # number of chars in prefix
			# get Page_title from Wx/xx/Page_title
			$data['realtitle'] = substr( $title, $prefixn );
		}
		return $data; # return an array with information
	}

	/**
	 * This returns simply true or false based on analyzePrefix().
	 * @param $title Title
	 * @param $onlyprefix bool
	 * @return Boolean
	 */
	static function validatePrefix( $title, $onlyprefix = false ) {
		$data = self::analyzePrefix( $title, $onlyprefix );
		return !$data['error'];
	}

	/**
	 * Get &testwiki=wx/xx and validate that prefix.
	 * Returns the array of analyzePrefix() on success.
	 * @return Array or false
	 */
	static function getUrlParam() {
		global $wgRequest;
		$urlParam = $wgRequest->getVal( 'testwiki' );
		if ( !$urlParam ) {
			return false;
		}
		$val = self::analyzePrefix( ucfirst( $urlParam ), true );
		if ( $val['error'] || !isset( $val['project'] ) || !isset( $val['lang'] )
			|| !$val['project'] || !$val['lang'] ) {
			return false;
		}
		$val['prefix'] = strtolower( $val['prefix'] );
		return $val;
	}

	/**
	 * Returns the project code or name if the given project code or name (or preference by default)
	 * is one of the projects using the format Wx/xxx (as defined in $wmincProjects)
	 * Returns false if it is not valid.
	 * @param $project String The project code
	 * @param $returnName Bool Whether to return the project name instead of the code
	 * @param $includeSister Bool Whether to include sister projects
	 * @return String or false
	 */
	static function getProject( $project = '', $returnName = false, $includeSister = false ) {
		global $wgUser, $wmincPref, $wmincProjects, $wmincSisterProjects;
		$url = self::getUrlParam();
		if ( $project ) {
			$r = $project; # Precedence to given value
		} elseif ( $url ) {
			$r = $url['project']; # Otherwise URL &testwiki= if set
		} else {
			$r = $wgUser->getOption( $wmincPref . '-project' ); # Defaults to preference
		}
		$projects = $includeSister ? array_merge( $wmincProjects, $wmincSisterProjects ) : $wmincProjects;
		if ( array_key_exists( $r, $projects ) ) {
			# If a code is given, return what is wanted
			return $returnName ? $projects[$r] : $r;
		} elseif ( array_search( $r, $projects ) ) {
			# If a name is given, return what is wanted
			return $returnName ? $r : array_search( $r, $projects );
		}
		# Unknown code or name given -> false
		return false;
	}

	/**
	 * Returns a simple boolean based on getProject()
	 * @param $project string
	 * @param $returnName bool
	 * @param $returnName bool
	 * @param $includeSister bool
	 * @return Bool
	 */
	static function isContentProject( $project = '', $returnName = false, $includeSister = false ) {
		return (bool)self::getProject( $project, $returnName, $includeSister );
	}

	/**
	 * display the prefix by the given project and code
	 * (or the URL &testwiki= or user preference if no parameters are given)
	 * @return String
	 */
	static function displayPrefix( $project = '', $code = '', $allowSister = false ) {
		global $wmincSisterProjects;
		if ( $project && $code ) {
			$projectvalue = $project;
			$codevalue = $code;
		} else {
			global $wgUser, $wmincPref;
			$url = self::getUrlParam();
			$projectvalue = ( $url ? $url['project'] : $wgUser->getOption( $wmincPref . '-project' ) );
			$codevalue = ( $url ? $url['lang'] : $wgUser->getOption( $wmincPref . '-code' ) );
		}
		$sister = (bool)( $allowSister && isset( $wmincSisterProjects[$projectvalue] ) );
		if ( self::isContentProject( $projectvalue ) || $sister ) {
			// if parameters are set OR it falls back to user pref and
			// he has a content project pref set  -> return the prefix
			return 'W' . $projectvalue . '/' . $codevalue; // return the prefix
		} else {
			// fall back to user pref with NO content pref set
			// -> still provide the value (probably 'none' or 'inc')
			return $projectvalue;
		}
	}

	/**
	 * Makes a full prefixed title of a given page title and namespace
	 * @param $ns Int numeric value of namespace
	 * @return object Title
	 */
	static function displayPrefixedTitle( $title, $ns = 0 ) {
		global $wgLang, $wmincTestWikiNamespaces;
		if ( in_array( $ns, $wmincTestWikiNamespaces ) ) {
			/* Standard namespace as defined by
			* $wmincTestWikiNamespaces, so use format:
			* TITLE + NS => NS:Wx/xxx/TITLE
			*/
			$title = Title::makeTitleSafe( $ns, self::displayPrefix() . '/' . $title );
		} else {
			/* Non-standard namespace, so use format:
			* TITLE + NS => Wx/xxx/NS:TITLE
			* (with localized namespace name)
			*/
			$title = Title::makeTitleSafe( null, self::displayPrefix() . '/' .
				$wgLang->getNsText( $ns ) . ':' . $title );
		}
		return $title;
	}

	static function magicWordVariable( &$magicWords ) {
		$magicWords[] = 'usertestwiki';
		return true;
	}

	static function magicWordValue( &$parser, &$cache, &$magicWordId, &$ret ) {
		$p = self::displayPrefix();
		$ret = $p ? $p : 'none';
		return true;
	}

	/**
	 * Whether we should show an error message that the page is unprefixed
	 * @param $title Title object
	 * @return Boolean
	 */
	static function shouldWeShowUnprefixedError( $title ) {
		global $wmincTestWikiNamespaces, $wmincProjectSite, $wmincPseudoCategoryNSes;
		$prefixdata = self::analyzePrefix( $title->getText() );
		$ns = $title->getNamespace();
		$categories = array_map( [ __CLASS__, 'preg_quote_slash' ], $wmincPseudoCategoryNSes );
		if ( !$prefixdata['error'] ) {
			# no error in prefix -> no error to show
			return false;
		} elseif ( self::displayPrefix() == $wmincProjectSite['short'] ) {
			# If user has "project" (Incubator) as test wiki preference, it isn't needed to check
			return false;
		} elseif ( !in_array( $ns, $wmincTestWikiNamespaces ) ) {
			# OK if it's not in one of the content namespaces
			return false;
		} elseif ( ( $ns == NS_CATEGORY || $ns == NS_CATEGORY_TALK ) &&
			preg_match( '/^(' . implode( '|', $categories ) . '):.+$/', $title->getText() ) ) {
			# whitelisted unprefixed categories
			return false;
		}
		return true;
	}

	/**
	 * This does several things:
	 * Disables editing pages belonging to existing wikis (+ shows message)
	 * Disables creating an unprefixed page (+ shows error message)
	 * See also: WikimediaIncubator::onShowMissingArticle()
	 * @return Boolean
	 */
	static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		$titletext = $title->getText();
		$prefixdata = self::analyzePrefix( $titletext );

		if ( self::getDBState( $prefixdata ) == 'existing' ) {
			if ( $prefixdata['prefix'] == $titletext &&
				( $title->exists() || $user->isAllowed( 'editinterface' ) ) ) {
				# if it's an info page, allow if the page exists or the user has 'editinterface' right
				return true;
			}
			# no permission if the wiki already exists
			$link = self::getSubdomain( $prefixdata['lang'],
				$prefixdata['project'], ( $title->getNsText() ? $title->getNsText() . ':' : '' ) .
				str_replace( ' ', '_', $prefixdata['realtitle'] ) );
			# faking external link to support prot-rel URLs
			$link = "[$link " . self::makeExternalLinkText( $link ) . "]";
			$result[] = [ 'wminc-error-wiki-exists', $link ];
			return $action != 'edit';
		}

		if ( !self::shouldWeShowUnprefixedError( $title ) || $action != 'edit' || $title->exists() ) {
			# only check if needed & if on page creation
			return true;
		} elseif ( $prefixdata['error'] == 'invalidlangcode' ) {
			$error[] = [ 'wminc-error-wronglangcode', $prefixdata['lang'] ];
		} elseif ( self::isContentProject() ) {
			# If the user has a test wiki pref, suggest a page title with prefix
			$suggesttitle = isset( $prefixdata['realtitle'] ) ?
				$prefixdata['realtitle'] : $titletext;
			$suggest = self::displayPrefixedTitle( $suggesttitle, $title->getNamespace() );
			# Suggest to create a prefixed page
			$error[] = [ 'wminc-error-unprefixed-suggest', $suggest ];
		} else {
			$error = 'wminc-error-unprefixed';
		}
		$result = $error;
		return $action != 'edit';
	}

	public static function onMovePageIsValidMove( Title $oldTitle, Title $newTitle, Status $status ) {
		if ( self::shouldWeShowUnprefixedError( $newTitle ) ) {
			# there should be an error with the new page title
			$status->fatal( 'wminc-error-move-unprefixed' );
			return false;
		}

		return true;
	}

	/**
	 * Return an error if the user wants to move
	 * an existing page to an unprefixed title
	 * @return Boolean
	 */
	static function checkPrefixMovePermissions( $oldtitle, $newtitle, $user, &$error ) {
		$status = new Status();
		self::onMovePageIsValidMove( $oldtitle, $newtitle, $status );
		if ( !$status->isOK() ) {
			$error = $status->getHTML();
			return false;
		}
		return true;
	}

	/**
	 * Add a link to Special:ViewUserLang from Special:Contributions/USERNAME
	 * if the user has 'viewuserlang' permission
	 * Based on code from extension LookupUser made by Tim Starling
	 */
	static function efLoadViewUserLangLink( $id, $nt, array &$links, SpecialPage $sp ) {
		if ( $sp->getUser()->isAllowed( 'viewuserlang' ) ) {
			$user = $nt->getText();
			$links['viewuserlang'] = $sp->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'ViewUserLang', $user ),
				$sp->msg( 'wminc-viewuserlang' )->text()
			);
		}
	}

	/**
	 * Convenience function to access $wgConf->get()
	 * @param $setting String: the setting to call
	 * @param $lang String: the language code
	 * @param $project String: the project code or name
	 * @return Mixed: the setting from $wgConf->settings
	 */
	public static function getConf( $setting, $lang, $project ) {
		if ( !self::canWeCheckDB() ) {
			return false;
		}
		global $wmincProjectDatabases, $wgConf;
		$wgConf->loadFullData();
		$lang = strtolower( $lang );
		$langHyphen = str_replace( '_', '-', $lang );
		$langUnderscore = str_replace( '-', '_', $lang );
		$projectName = self::getProject( $project, true, true );
		$projectCode = self::getProject( $project, false, true );
		if ( !$projectCode ) {
			global $wmincMultilingualProjects;
			$projectCode = array_search( $project, $wmincMultilingualProjects );
		}
		$site = strtolower( $projectName );
		$params = [
			'lang' => $langHyphen,
			'site' => $site,
			# I don't see any other way than to hardcode it here (from: CommonSettings.php)
			'stdlogo' => "//upload.wikimedia.org/$site/$langHyphen/b/bc/Wiki.png",
		];
		$dbSuffix = isset( $wmincProjectDatabases[$projectCode] ) ?
			$wmincProjectDatabases[$projectCode] : $site;
		return $wgConf->get( $setting, $langUnderscore . $dbSuffix, $dbSuffix, $params );
	}

	/**
	 * Do we know the databases of the existing wikis?
	 * @return Boolean
	 */
	static function canWeCheckDB() {
		global $wmincExistingWikis, $wmincProjectDatabases;
		if ( !is_array( $wmincProjectDatabases ) || !is_array( $wmincExistingWikis ) ) {
			return false; # We don't know the databases
		}
		return true; # Should work now
	}

	/**
	 * Given an incubator testwiki prefix, get the database name of the
	 * corresponding wiki, whether it exists or not
	 * @param $prefix Array from WikimediaIncubator::analyzePrefix();
	 * @return false or string
	 */
	static function getDB( $prefix ) {
		if ( !self::canWeCheckDB() ) {
			return false;
		} elseif ( !$prefix || $prefix['error'] ) {
			return false; # shouldn't be, but you never know
		}
		global $wmincProjectDatabases, $wgDummyLanguageCodes;
		$dbLang = str_replace( '-', '_', $prefix['lang'] );
		$project = $prefix['project'];
		$dbProject = isset( $wmincProjectDatabases[$project] ) ?
			$wmincProjectDatabases[$project] : $project;
		$redirectcode = array_search( $prefix['lang'], $wgDummyLanguageCodes );
		if ( $redirectcode ) {
			$prefix['lang'] = $redirectcode;
		}
		return strtolower( $dbLang . $dbProject );
	}

	/**
	 * @return false or array with closed databases
	 */
	static function getDBClosedWikis() {
		global $wmincClosedWikis;
		if ( !self::canWeCheckDB() || !$wmincClosedWikis ) {
			return false;
		}
		# Is probably a file, but it might be that an array is given
		return is_array( $wmincClosedWikis ) ? $wmincClosedWikis :
			array_map( 'trim', file( $wmincClosedWikis ) );
	}

	/**
	 * @param $prefix Array from WikimediaIncubator::analyzePrefix();
	 * @return false or string 'existing' 'closed' 'missing'
	 */
	static function getDBState( $prefix ) {
		$db = self::getDB( $prefix );
		if ( !$db ) {
			return false;
		}
		global $wmincExistingWikis;
		$closed = self::getDBClosedWikis();
		if ( !in_array( $db, $wmincExistingWikis ) ) {
			return 'missing'; # not in the list
		} elseif ( is_array( $closed ) && in_array( $db, $closed ) ) {
			return 'closed'; # in the list of closed wikis
		}
		return 'existing';
	}

	/**
	 * If existing wiki: show message or redirect if &testwiki is set to that
	 * Missing article on Wx/xx info pages: show welcome page
	 * See also: WikimediaIncubator::onGetUserPermissionsErrors()
	 * @return True
	 */
	static function onShowMissingArticle( $article ) {
		$title = $article->getTitle();
		$prefix = self::analyzePrefix( $title, true /* only info pages */,
			true /* also sister projects */ );
		if ( !$prefix['error'] ) {
			self::onShowMissingArticleForInfoPages( $article, $prefix );
			return true;
		}

		global $wgOut, $wmincSisterProjects;
		$prefix2 = self::analyzePrefix( $title, false, true );
		$p = isset( $prefix2['project' ] ) ? $prefix2['project'] : '';
		if ( self::getDBState( $prefix2 ) == 'existing' ) {
			$link = self::getSubdomain( $prefix2['lang'], $p,
				( $title->getNsText() ? $title->getNsText() . ':' : '' ) . $prefix2['realtitle'] );
			if ( self::displayPrefix() == $prefix2['prefix'] ) {
				# Redirect to the existing wiki if the user has this wiki as preference
				$wgOut->redirect( $link );
				return true;
			} else {
				# Show a link to the existing wiki
				$showLink = self::makeExternalLinkText( $link, true );
				$wgOut->addHtml( '<div class="wminc-wiki-exists">' .
					wfMessage( 'wminc-error-wiki-exists' )->rawParams( $showLink )->escaped() .
				'</div>' );
			}
		} elseif ( array_key_exists( $p, $wmincSisterProjects ) ) {
			# A sister project is not hosted here, so direct the user to the relevant wiki
			$link = self::getSubdomain( $prefix2['lang'], $p,
				( $title->getNsText() ? $title->getNsText() . ':' : '' ) . $prefix2['realtitle'] );
				$showLink = self::makeExternalLinkText( $link, true );
				$wgOut->addHtml( '<div class="wminc-wiki-sister">' .
					wfMessage( 'wminc-error-wiki-sister' )->rawParams( $showLink )->escaped() .
				'</div>' );
		} elseif ( self::shouldWeShowUnprefixedError( $title ) ) {
			# Unprefixed pages
			if ( self::isContentProject() ) {
				# If the user has a test wiki pref, suggest a page title with prefix
				$suggesttitle = isset( $prefix2['realtitle'] ) ?
					$prefix2['realtitle'] : $title->getText();
				$suggest = self::displayPrefixedTitle( $suggesttitle, $title->getNamespace() );
				# Suggest to create a prefixed page
				$wgOut->addHtml( '<div class="wminc-unprefixed-suggest">' .
					wfMessage( 'wminc-error-unprefixed-suggest', $suggest )->parseAsBlock() .
				'</div>' );
			} else {
				$wgOut->addWikiMsg( 'wminc-error-unprefixed' );
			}
		}
		return true;
	}

	/*
	 * Use the InfoPage class to show a nice welcome page
	 * depending on whether it belongs to an existing, closed or missing wiki
	 * @param $article Article
	 * @param $prefix array
	 */
	static function onShowMissingArticleForInfoPages( $article, $prefix ) {
		global $wgOut;
		$title = $article->getTitle();
		$wgOut->addModuleStyles( 'WikimediaIncubator.InfoPage' );
		$infopage = new InfoPage( $title, $prefix );
		$infopage->mDbStatus = $dbstate = self::getDBState( $prefix );
		if ( $dbstate == 'existing' ) {
			$infopage->mSubStatus = 'beforeincubator';
			$wgOut->addHtml( $infopage->showExistingWiki() );
		} elseif ( $dbstate == 'closed' ) {
			$infopage->mSubStatus = 'imported';
			$wgOut->addHtml( $infopage->showIncubatingWiki() );
		} elseif ( self::getMainPage( $prefix['lang'], $prefix['prefix'] )->exists() ) {
			$infopage->mSubStatus = 'open';
			$wgOut->addHtml( $infopage->showIncubatingWiki() );
		} else {
			$wgOut->addHtml( $infopage->showMissingWiki() );
		}
		# Set the page title from "Wx/xyz - Incubator" to "Wikiproject Language - Incubator"
		$wgOut->setHTMLTitle( wfMessage( 'pagetitle', $infopage->mFormatTitle )->text() );
	}

	public static function onParserFirstCallInit( &$parser ) {
		$parser->setFunctionHook( 'infopage', 'WikimediaIncubator::renderParserFunction' );
		return true;
	}

	/**
	 * #infopage parser function
	 * @return array
	 */
	public static function renderParserFunction( &$parser ) {
		$title = $parser->getTitle();
		$prefix = self::analyzePrefix( $title );
		if ( $prefix['error'] ) {
			return '<span class="error">' .
				wfMessage( 'wminc-infopage-error' )->plain() . '</span>';
		}
		$infopage = new InfoPage( $title, $prefix );
		$infopage->mOptions = [
			'status' => 'open',
			# other (optional) options: mainpage
		];

		$parseOptions = func_get_args();
		array_shift( $parseOptions ); # not $parser
		foreach ( $parseOptions as $parseOption ) {
			if ( strpos( $parseOption, '=' ) === false ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $parseOption, 2 );
			$key = strtolower( trim( $key ) );
			$infopage->mOptions[$key] = trim( $value );
		}

		$infopage->mSubStatus = $infopage->mOptions['status'];

		$parser->getOutput()->addModuleStyles( 'WikimediaIncubator.InfoPage' );
		$parser->getOptions()->getUserLangObj(); # we have to split the cache by language
		$parser->getOutput()->setTitleText( $infopage->mFormatTitle ); # sets <h1> & <title>

		if ( in_array( $infopage->mSubStatus, [ 'created', 'beforeincubator' ] ) ) {
			$return = $infopage->showExistingWiki();
		} elseif ( self::getMainPage( $prefix['lang'], $prefix['prefix'] )->exists() ) {
			$return = $infopage->showIncubatingWiki();
		} else {
			// open wiki, no test wiki main page => missing
			$return = $infopage->showMissingWiki();
		}

		return [ $return, 'noparse' => true, 'nowiki' => true, 'isHTML' => true ];
	}

	/**
	 * When creating a new info page, help the user by prefilling it
	 * @return True
	 */
	public static function onEditFormPreloadText( &$text, &$title ) {
		$prefix = self::analyzePrefix( $title, true /* only info page */,
			false /* no sister projects */ );
		if ( !$prefix['error'] ) {
			$text = wfMessage( 'wminc-infopage-prefill', $prefix['prefix'] )->plain();
		}
		return true;
	}

	/**
	 * This forms a URL based on the language and project.
	 * @param $lang String Language code
	 * @param $project String Project code or name
	 * @param $title String Page name
	 * @return String
	 */
	public static function getSubdomain( $lang, $project, $title = '' ) {
		global $wgArticlePath;
		return self::getConf( 'wgServer', $lang, $project ) .
			( $title ? str_replace( '$1', $title, $wgArticlePath ) : '' );
	}

	/**
	 * make "Wx/xxx/Main Page"
	 * @param $langCode String: The language code
	 * @param $prefix Null|String: the "Wx/xxx" prefix to add
	 * @return Title
	 */
	public static function getMainPage( $langCode, $prefix = null ) {
		# Take the "mainpage" msg in the given language
		$msg = wfMessage( 'mainpage' )->inLanguage( $langCode )->plain();
		$mainpage = $prefix !== null ? $prefix . '/' . $msg : $msg;
		$title = Title::newFromText( $mainpage );
		if ( $title->exists() ) {
			return $title; # If it exists, use it
		}
		$mainpage = $prefix !== null ? $prefix . '/Main_Page' : 'Main_Page';
		$title2 = Title::newFromText( $mainpage );
		if ( $title2->exists() ) {
			return $title2; # Try the English "Main Page"
		}
		return $title; # Nothing exists, use the original again
	}

	/**
	 * Redirect if &goto=mainpage on info pages
	 * @return True
	 */
	public static function onMediaWikiPerformAction( $output, $page, $title, $user, $request ) {
		$prefix = self::analyzePrefix( $title, true );
		if ( $prefix['error'] || $request->getVal( 'goto' ) != 'mainpage' ) {
			return true;
		}

		$dbstate = self::getDBState( $prefix );
		if ( !$dbstate ) {
			return true;
		}
		if ( $dbstate == 'existing' ) {
			# redirect to the existing lang.wikiproject.org if it exists
			$output->redirect( self::getSubdomain( $prefix['lang'], $prefix['project'] ) );
			return false;
		}
		$params['redirectfrom'] = 'infopage';
		$uselang = $request->getVal( 'uselang' );
		if ( $uselang ) {
			# pass through the &uselang parameter
			$params['uselang'] = $uselang;
		}
		$mainpage = self::getMainPage( $prefix['lang'], $prefix['prefix'] );
		if ( $mainpage->exists() ) {
			# Only redirect to the main page if that page exists
			$output->redirect( $mainpage->getFullURL( $params ) );
			return false;
		}
		return true;
	}

	/**
	 * Valid Wx/xyz info pages should be considered as existing pages
	 * Note: TitleIsAlwaysKnown hook exists since 1.20
	 * @param $title Title
	 * @param $isKnown
	 * @return Boolean
	 */
	public static function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		$prefix = self::analyzePrefix( $title, true, true );
		if ( !$prefix['error'] ) {
			$isKnown = true;
		}
		return true;
	}

	/**
	 * Whether we should use the feature of custom logos per project
	 * @param $title Title object
	 * @return false or Array from analyzePrefix()
	 */
	static function shouldWeSetCustomLogo( $title ) {
		$prefix = self::analyzePrefix( $title );

		# Maybe do later something like if ( isContentProject() && 'recentchanges' ) { return true; }

		# return if the page does not have a valid prefix (info page is considered valid)
		if ( $prefix['error'] ) {
			return false;
		}
		# display the custom logo only if &testwiki=wx/xx or
		# the user's pref is set to the current test wiki
		if ( self::displayPrefix() != $prefix['prefix'] ) {
			return false;
		}
		return $prefix;
	}

	/**
	 * Display a different logo in current test wiki
	 * if it is set in MediaWiki:Incubator-logo-wx-xxx
	 * and if accessed through &testwiki=wx/xxx
	 * or if the user preference is set to wx/xxx
	 * @return Boolean
	 */
	static function fnTestWikiLogo( &$out ) {
		$setLogo = self::shouldWeSetCustomLogo( $out->getTitle() );
		if ( !$setLogo ) {
			return true;
		}
		global $wgLogo;
		$prefixForPageTitle = str_replace( '/', '-', strtolower( $setLogo['prefix'] ) );
		$logoMsg = wfMessage( 'Incubator-logo-' . $prefixForPageTitle )->inContentLanguage()->plain();
		$file = wfFindFile( Title::newFromText( $logoMsg, NS_FILE ) );
		if ( $file ) {
			$thumb = $file->transform( [ 'width' => 135, 'height' => 135 ] );
			$wgLogo = $thumb->getUrl();
		} else {
			# if MediaWiki:Incubator-logo-wx-xx(x) doesn't exist,
			# take a general, default logo for that project
			$wgLogo = self::getConf( 'wgLogo', 'en', $setLogo['project'] );
		}
		return true;
	}

	/**
	 * Make the page content language depend on the test wiki
	 * Info pages are in the user language, they're localised
	 * @return true
	 */
	static function onPageContentLanguage( $title, &$pageLang ) {
		global $wgLang;
		$prefix = self::analyzePrefix( $title, /* onlyInfoPage*/ false );
		if ( !$prefix['error'] ) {
			$pageLang = self::validatePrefix( $title, true ) ?
				$wgLang : Language::factory( $prefix['lang'] );
		}
		return true;
	}

	/**
	 * Search: Adapt the default message to show a more descriptive one,
	 * along with an adapted link.
	 * @param $title Title
	 * @param $params array
	 * @return true
	 */
	public static function onSpecialSearchCreateLink( $title, &$params ) {
		if ( $title->isKnown() ) {
			return true;
		}
		global $wmincProjectSite, $wmincTestWikiNamespaces;
		$prefix = self::displayPrefix();

		$newNs = $title->getNamespace();
		$newTitle = $title->getText();
		$newTitleData = self::analyzePrefix( $newTitle, false, true );
		if ( !in_array( $title->getNamespace(), $wmincTestWikiNamespaces ) ) {
			# namespace not affected by the prefix system: show normal msg
			return true;
		} elseif ( $prefix == $wmincProjectSite['short'] ) {
			$newNs = NS_PROJECT;
		} elseif ( self::getDBState( $newTitleData ) == 'existing' ) {
			# the wiki already exists
			$link = self::getSubdomain(
				$newTitleData['lang'], $newTitleData['project'],
				( $title->getNsText() ? $title->getNsText() . ':' : '' ) .
				str_replace( ' ', '_', $newTitleData['realtitle'] )
			);
			$params[0] = 'wminc-error-wiki-exists';
			$params[1] = "[$link " . self::makeExternalLinkText( $link ) . "]";
			return true;
		} elseif ( $newTitleData['error'] ) {
			# only add a prefix to the title if there is no prefix
			# ('error' by analyzePrefix)
			$newTitle = $prefix . '/' . $newTitle;
		}

		$t = Title::newFromText( $newTitle, $newNs );
		if ( $t->isKnown() ) {
			# use the default message if the suggested title exists
			$params[0] = 'searchmenu-exists';
			$params[1] = wfEscapeWikiText( $t->getPrefixedText() );
			return true;
		}
		$params[] = wfEscapeWikiText( $t->getPrefixedText() );
		$params[0] = $prefix && $prefix != 'none'
			? 'wminc-search-nocreate-suggest' : 'wminc-search-nocreate-nopref';
		return true;
	}

	/**
	 * Search: Add an input form to enter a test wiki prefix.
	 * @return true
	 */
	public static function onSpecialSearchPowerBox( &$showSections, $term, $opts ) {
		$showSections['testwiki'] = Xml::label( wfMessage( 'wminc-testwiki' )->text(), 'testwiki' )
			. ' ' . Xml::input( 'testwiki', 20, self::displayPrefix(), [ 'id' => 'testwiki' ] );
		return true;
	}

	/**
	 * Search: Search by default in the test wiki of the user's preference (or url &testwiki).
	 * @return true
	 */
	public static function onSpecialSearchSetupEngine( $search, $profile, $engine ) {
		if ( !isset( $search->prefix ) || !$search->prefix ) {
			$search->prefix = self::displayPrefix();
		}
		return true;
	}

	private static function preg_quote_slash( $str ) {
		return preg_quote( $str, '/' );
	}

	/**
	 * @param $url String
	 * @param $callLinker Boolean Whether to call makeExternalLink()
	 */
	public static function makeExternalLinkText( $url, $callLinker = false ) {
		# when displaying a URL, if it contains 'http://' or 'https://' it's ok to leave it,
		# but for protocol-relative URLs, it's nicer to remove the '//'
		$linktext = ltrim( $url, '/' );
		return $callLinker ? Linker::makeExternalLink( $url, $linktext ) : $linktext;
	}
}
