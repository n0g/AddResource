<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if (!defined('MEDIAWIKI')) {
    echo <<<EOT
To install the AddResource extension, you must put at least the following
code in LocalSettings.php:
require_once( "$IP/extensions/AddResource/AddResource.php" );

Note that several variables have to be set for this extension to become
useful. For full documentation please see:
    https:///fs.fsinf.at/wiki/AddResource
EOT;
    exit( 1 );
}

/**
 * Define some useful constants.
 */
define("ADD_RESOURCE_ACTION_UPLOAD", 1);
define("ADD_RESOURCE_ACTION_SUBPAGE", 2);
define("ADD_RESOURCE_ACTION_LINK", 3);
define("ADD_RESOURCE_ACTION_NAME", 'Action');
define("ADD_RESOURCE_ACTION_FIELD", 'wpAction');
define("ADD_RESOURCE_REFERER_NAME", 'ForArticle');
define("ADD_RESOURCE_REFERER_FIELD", "wpForArticle");

/**
 * Some extension boilerplate
 */
$dir = dirname(__FILE__);
$wgExtensionMessagesFiles['AddResource'] = $dir . '/AddResource.i18n.php';
$wgSpecialPages[ 'AddResource' ] = 'AddResource';
$wgSpecialPageGroups[ 'AddResource' ] = 'other';

$wgExtensionCredits['specialpage'][] = array(
    'path' => __FILE__,
    'name' => 'AddResource',
    'description' => 'This special page allows you to \'\'\'attach\'\'\' resources to a given page',
    'version' => '2.0.1-1.16.0',
    'author' => 'Mathias Ertl',
    'url' => 'https://fs.fsinf.at/wiki/AddResource',
);

/**
 * Autoload classes
 */
$wgAutoloadClasses['AddResource'] = $dir . '/SpecialAddResource.php';
$wgAutoloadClasses['UploadFileForm'] = $dir . '/ResourceForms.php';
$wgAutoloadClasses['SubpageForm'] = $dir . '/ResourceForms.php';
$wgAutoloadClasses['ExternalRedirectForm'] = $dir . '/ResourceForms.php';
$wgAutoloadClasses['UploadResourceFromFile'] = $dir . '/ResourceUploadBackends.php';
$wgAutoloadClasses['UploadResourceFromStash'] = $dir . '/ResourceUploadBackends.php';

/**
 * Hook registration.
 */
$wgHooks['LanguageGetSpecialPageAliases'][] = 'efAddResourceLocalizedPageName';
$wgHooks['UploadCreateFromRequest'][] = 'wgAddResourceGetUploadRequestHandler';
$wgHooks['SkinTemplateNavigation::SpecialPage'][] = 'efAddResourceSpecialPage';

/**
 * Default values for most options.
 *
 * TODO.
 */
#$wgCategoryTreeDefaultOptions      = array();

function getResourcesUrl($title) {
    $resources = SpecialPage::getTitleFor('Resources');
    return $resources->getLocalURL() .'/'. $title->getPrefixedDBkey();
}

/**
 * These functions adds the localized pagename of the "Add resource" special-
 * page.
 *
 * @param array $specialPageArray the current array of special pages
 * @param unknown $code unknown.
 */
function efAddResourceLocalizedPageName( &$specialPageArray, $code) {
        wfLoadExtensionMessages('AddResource');
        $textMain = wfMsgForContent('addresource');
        $textUser = wfMsg('addresource');

        # Convert from title in text form to DBKey and put it into the alias array:
        $titleMain = Title::newFromText( $textMain );
        $titleUser = Title::newFromText( $textUser );
        $specialPageArray['AddResource'][] = $titleMain->getDBKey();
        $specialPageArray['AddResource'][] = $titleUser->getDBKey();

        return true;
}

function efAddResourceSpecialPage($template, $links) {
    global $wgTitle, $wgRequest, $wgUser, $wgAddResourceTab;

    // return if we are not on the right special page
    if (!$wgTitle->isSpecial('AddResource')) {
        return true;
    }

    // parse subpage-part. We cannot use $wgTitle->getSubpage() because the
    // special namespaces doesn't have real subpages
    $prefixedText = $wgTitle->getPrefixedText();
    if (strpos($prefixedText, '/') === FALSE) {
        return true; // no page given
    }
    $parts = explode( '/', $prefixedText);
    $pageName = $parts[count( $parts ) - 1];

    $title = Title::newFromText($pageName)->getSubjectPage();
    $talkTitle = $title->getTalkPage();

    // Get AddResource URL:
    $resourceCount = getResourceCount($title);
    $resourcesUrl = getResourcesUrl($title);
    $resourcesText = getResourceTabText($resourceCount);
    $resourcesClass = $resourceCount > 0 ? 'is_resource' : 'new is_resource';

    $head = array (
        $title->getNamespaceKey('') => array(
            'class' => $title->exists() ? null : 'new',
            'text' => $title->getText(),
            'href' => $title->getLocalUrl(),
        ),
        'resources' => array(
            'class' => $resourcesClass,
            'text' => $resourcesText,
            'href' => $resourcesUrl,
        ),
    );
    $tail = array (
        $title->getNamespaceKey('') . '_talk' => array(
            'class' => $talkTitle->exists() ? null : 'new',
            'text' => wfMsg('Talk'),
            'href' => $talkTitle->getLocalUrl(),
        )
    );
    $resourceCount = getResourceCount($title);

    $links['namespaces'] = array_merge($head, $links['namespaces'], $tail);
    $links['namespaces']['special']['text'] = '+';

    return true;
}

/**
 * Sets the upload handler to our special class in case the POST data includes
 * the ADD_RESOURCE_REFERER_FIELD value
 */
function wgAddResourceGetUploadRequestHandler( $type, $className ) {
    global $wgRequest;
    if ( ! $wgRequest->getText(ADD_RESOURCE_REFERER_FIELD) ) {
        return true;
    }

    switch ( $type ) {
        case "File":
            $className = 'UploadResourceFromFile';
            break;
        case "Stash":
            $className = 'UploadResourceFromStash';
            break;
        default:
            break;
    }
    return true;
}

/**
 * Primitive function that returns HTML for a Banner with the given text.
 * color is either red or green, default is red.
 */
function getBanner( $text, $div_id = 'random banner', $color = 'red' ) {
    $s = '<div id="' . $div_id . '">';
    $s .= '<table align="center" border="0" cellpadding="5" cellspacing="2"';
    switch ($color) {
        case 'red':
            $s .= '    style="border: 1px solid #FFA4A4; background-color: #FFF3F3; border-left: 5px solid #FF6666">';
            break;
        case 'green':
            $s .= '    style="border: 1px solid #A4FFA4; background-color: #F3FFF3; border-left: 5px solid #66FF66">';
            break;
        case 'grey':
            $s .= '    style="border: 1px solid #BDBDBD; background-color: #E6E6E6; border-left: 5px solid #6E6E6E">';
    }

    $s .= '<tr><td style=font-size: 95%;>';
    $s .= $text;
    $s .= '</td></tr></table></div>';
    return $s;
}

?>
