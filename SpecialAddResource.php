<?php

/**
 * The AddResource special page
 */
class SpecialAddResource extends SpecialPage
{
    private $mAction;
    private $mRequest;

    private $mSubpageDest;

    private $mLinkUrl;
    private $mLinkTitle;
    private $mLinkDesc;

    private $mUpload;
    private $mTokenOk;
    private $mCancelUpload;
    private $mUploadClicked;

    /**
      * Statically set some variables that are set dynamically by
      * Special:Upload. Some code is copied 1:1 from MediaWiki source code,
      * and this code needs the variables to be present (we set sensible
      * defaults here).
     */
    private $mIgnoreWarning = true;
    private $mWatchthis = false;
    private $mLicense = '';
    private $mCopyrightStatus = '';
    private $mCopyrightSource = '';

    function __construct($request = null) {
        parent::__construct('AddResource');

        global $wgRequest;
        $this->loadRequest(is_null($request) ? $wgRequest : $request);
    }

    private function loadRequest($request) {
        global $wgUser;
        $this->mRequest = $request;
        $this->mAction = $request->getInt(ADD_RESOURCE_ACTION_FIELD);

        switch ($this->mAction) {
        case ADD_RESOURCE_ACTION_UPLOAD;
            $this->mUpload = UploadBase::createFromRequest($request);
            # used by copied processUpload()
            $this->mUploadClicked = true;
            $this->mComment = $request->getVal('wpUploadDescription');
            break;
        case ADD_RESOURCE_ACTION_SUBPAGE;
            $this->mSubpageDest = $request->getVal('wpSubpageDest');
            break;
        case ADD_RESOURCE_ACTION_LINK:
            $this->mLinkUrl = $request->getVal('wpLinkUrl');
            $this->mLinkTitle = $request->getVal('wpLinkTitle');
            $this->mLinkDesc = $request->getVal('wpLinkDesc');
            break;
        default:
            break;
        };

        $this->mTokenOk = $wgUser->matchEditToken(
            $request->getVal('wpEditToken')
        );
        $this->mCancelUpload = false;
    }

    private function addSectionHeader($message, $class) {
        global $wgOut;
        $wgOut->wrapWikiMsg("<h2 class='mw-addresourcesection' id='mw-addresource-$class'>$1</h2>", $message);
    }

    /**
     * this is the main worker function that calls all other functions,
     * also depending on HTTP-variables (?foo=something). After this
     * function you have a complete special page...
     */
    function execute($par) {
        global $wgOut, $wgRequest, $wgUser, $wgEnableUploads, $wgEnableExternalRedirects;
        $this->setHeaders();

        /* make a Title object from $par */
        if ($par) {
            $this->targetTitle = Title::newFromText($par);
            $this->param = $par;
        } else { /* if nothing was specified */
            $wgOut->addWikiText(wfMessage('noParameterHelp')->text());
            return;
        }

        /* header text, title */
        $wgOut->setPagetitle(wfMessage('addResourcesPageTitle', $this->targetTitle->getPrefixedText())->text());
        $wgOut->addWikiText(
            wfMessage('explanation',
                $this->targetTitle->getFullText(),
                SpecialPage::getTitleFor('Resources'),
                wfMessage('upload_header')->text(),
                wfMessage('subpage_header')->text(),
                wfMessage('link_header')->text()
            )->text()
        );

        # If we are not allowed to do *anything*, we display a red warning message.
        if (!($wgUser->isAllowed('edit') && $wgUser->isAllowed('createpage'))
                && ! $wgUser->isAllowed('upload')) {
            if ($wgUser->isLoggedIn())
                $wgOut->addHTML(getBanner(wfMessage('not_allowed')->text()));
            else {
                $loginPage = $this->getLoginLink(wfMessage('login_text')->text());
                $wgOut->addHTML(getBanner(wfMessage('not_allowed_anon', $loginPage)->text()));
            }
            return;
        }

        /* add the various chapters */
        if ($wgEnableUploads == True)
            $this->uploadChapter();
        if ($this->targetTitle->exists())
            $this->subpageChapter();
        if ($wgEnableExternalRedirects == True)
            $this->linkChapter();
    }

    /**
     * Display the upload chapter.
     *
     * Parts of this function are a 1:1 copy of SpecialUpload::execute() found
     * in includes/specials/SpecialUpload.php, version 1.21.1. See
     * inline-comments for exact details.
     */
    private function uploadChapter() {
        global $wgOut, $wgUser;

        # Unsave the temporary file in case this was a cancelled upload
        if ($this->mCancelUpload) {
            if (!$this->unsaveUploadedFile()) {
                # Something went wrong, so unsaveUploadedFile showed a warning
                return;
            }
        }

        # we need a header no matter what:
        $this->addSectionHeader('upload_header', 'upload');
        global $wgRequest;

        /**
         * Start copy of SpecialUpload::execute()
         */
        # Process upload or show a form
        if (
            $this->mTokenOk && !$this->mCancelUpload &&
            ( $this->mUpload && $this->mUploadClicked )
        ) {
            $this->processUpload();
        } else {
            # Backwards compatibility hook
            if( !wfRunHooks( 'UploadForm:initial', array( &$this ) ) ) {
                wfDebug( "Hook 'UploadForm:initial' broke output of the upload form" );
                return;
            }
            $this->showUploadForm( $this->getUploadForm() );
        }

        # Cleanup
        if ( $this->mUpload ) {
            $this->mUpload->cleanupTempFile();
        }
        /**
         * END copy of SpecialUpload::execute()
         */
    }

    /**
     * Implementation of getUploadForm()
     */
    protected function getUploadForm($message = '', $sessionKey = '', $hideIgnoreWarning = false) {
        $form = new UploadFileForm($this->targetTitle);
        $form->setTitle($this->getTitle($this->targetTitle));

        # display any upload error
        $form->addPreText($message);

        return $form;
    }

    /**
     * Implementation of showUploadForm()
     */
    protected function showUploadForm($form) {
        $form->show();
    }

    /**
     * This functionis a 1:1 copy of class SpecialUpload found in
     * includes/specials/SpecialUpload.php, version 1.21.1. The only
     * difference is the different redirect at the end.
     */
    private function processUpload() {
        // Fetch the file if required
        $status = $this->mUpload->fetchFile();
        if (!$status->isOK()) {
            $this->showUploadError($this->getOutput()->parse($status->getWikiText()));
            return;
        }

        if (!wfRunHooks('UploadForm:BeforeProcessing', array(&$this))) {
            wfDebug("Hook 'UploadForm:BeforeProcessing' broke processing the file.\n");
            // This code path is deprecated. If you want to break upload processing
            // do so by hooking into the appropriate hooks in UploadBase::verifyUpload
            // and UploadBase::verifyFile.
            // If you use this hook to break uploading, the user will be returned
            // an empty form with no error message whatsoever.
            return;
        }

        // Upload verification
        $details = $this->mUpload->verifyUpload();
        if ($details['status'] != UploadBase::OK) {
            $this->processVerificationError($details);
            return;
        }

        // Verify permissions for this title
        $permErrors = $this->mUpload->verifyTitlePermissions($this->getUser());
        if ($permErrors !== true) {
            $code = array_shift($permErrors[0]);
            $this->showRecoverableUploadError($this->msg($code, $permErrors[0])->parse());
            return;
        }

        $this->mLocalFile = $this->mUpload->getLocalFile();

        // Check warnings if necessary
        if (!$this->mIgnoreWarning) {
            $warnings = $this->mUpload->checkWarnings();
            if ($this->showUploadWarning($warnings)) {
                return;
            }
        }

        // Get the page text if this is not a reupload
        if (!$this->mForReUpload) {
            $pageText = self::getInitialPageText($this->mComment, $this->mLicense,
                $this->mCopyrightStatus, $this->mCopyrightSource);
        } else {
            $pageText = false;
        }
        $status = $this->mUpload->performUpload($this->mComment, $pageText, $this->mWatchthis, $this->getUser());
        if (!$status->isGood()) {
            $this->showUploadError($this->getOutput()->parse($status->getWikiText()));
            return;
        }

        // Success, redirect to description page
        $this->mUploadSuccessful = true;
        //wfRunHooks('SpecialUploadComplete', array(&$this));
        //$this->getOutput()->redirect($this->mLocalFile->getTitle()->getFullURL());

        /**
         * The previous two lines are in the original function. We don't need
         * the hook and we need a different redirect.
         */
        $redir = SpecialPage::getTitleFor('Resources', $this->targetTitle->getPrefixedText());
        $this->getOutput()->redirect($redir->getFullURL());
    }

    /**
     * This functionis a 1:1 copy of class SpecialUpload found in
     * includes/specials/SpecialUpload.php, version 1.21.1.
     */
    protected function processVerificationError($details) {
                global $wgFileExtensions;

        switch( $details['status'] ) {

            /** Statuses that only require name changing **/
            case UploadBase::MIN_LENGTH_PARTNAME:
                $this->showRecoverableUploadError( $this->msg( 'minlength1' )->escaped() );
                break;
            case UploadBase::ILLEGAL_FILENAME:
                $this->showRecoverableUploadError( $this->msg( 'illegalfilename',
                    $details['filtered'] )->parse() );
                break;
            case UploadBase::FILENAME_TOO_LONG:
                $this->showRecoverableUploadError( $this->msg( 'filename-toolong' )->escaped() );
                break;
            case UploadBase::FILETYPE_MISSING:
                $this->showRecoverableUploadError( $this->msg( 'filetype-missing' )->parse() );
                break;
            case UploadBase::WINDOWS_NONASCII_FILENAME:
                $this->showRecoverableUploadError( $this->msg( 'windows-nonascii-filename' )->parse() );
                break;

            /** Statuses that require reuploading **/
            case UploadBase::EMPTY_FILE:
                $this->showUploadError( $this->msg( 'emptyfile' )->escaped() );
                break;
            case UploadBase::FILE_TOO_LARGE:
                $this->showUploadError( $this->msg( 'largefileserver' )->escaped() );
                break;
            case UploadBase::FILETYPE_BADTYPE:
                $msg = $this->msg( 'filetype-banned-type' );
                if ( isset( $details['blacklistedExt'] ) ) {
                    $msg->params( $this->getLanguage()->commaList( $details['blacklistedExt'] ) );
                } else {
                    $msg->params( $details['finalExt'] );
                }
                $msg->params( $this->getLanguage()->commaList( $wgFileExtensions ),
                    count( $wgFileExtensions ) );

                // Add PLURAL support for the first parameter. This results
                // in a bit unlogical parameter sequence, but does not break
                // old translations
                if ( isset( $details['blacklistedExt'] ) ) {
                    $msg->params( count( $details['blacklistedExt'] ) );
                } else {
                    $msg->params( 1 );
                }

                $this->showUploadError( $msg->parse() );
                break;
            case UploadBase::VERIFICATION_ERROR:
                unset( $details['status'] );
                $code = array_shift( $details['details'] );
                $this->showUploadError( $this->msg( $code, $details['details'] )->parse() );
                break;
                        case UploadBase::HOOK_ABORTED:
                if ( is_array( $details['error'] ) ) { # allow hooks to return error details in an array
                    $args = $details['error'];
                    $error = array_shift( $args );
                } else {
                    $error = $details['error'];
                    $args = null;
                }

                $this->showUploadError( $this->msg( $error, $args )->parse() );
                break;
            default:
                throw new MWException( __METHOD__ . ": Unknown value `{$details['status']}`" );
        }
    }

    /**
     * Display the subpage chapter
     */
    private function subpageChapter() {
        global $wgOut, $wgUser;

        $this->addSectionHeader('subpage_header', 'subpage');

        # check if we are allowed to create subpages:
        if (!($wgUser->isAllowed('edit') && $wgUser->isAllowed('createpage'))) {
            $link = $this->getLoginLink(wfMessage('login_text')->text());
            $wgOut->addHTML(getBanner(wfMessage('createpage_not_allowed', wfMessage('subpages')->text(), $link)->text(),
                'createpage_not_allowed', 'grey'));
            return;
        }

        $form = new SubpageForm($this->mAction, $this->targetTitle, array(
            'dest' => $this->mSubpageDest,
        ));
        $form->setTitle($this->getTitle($this->targetTitle));
        if ($this->mAction != 'subpage') {
#            $form->setMethod('get');
        }
        $form->show();
    }

    /**
     * Display the link chapter.
     */
    private function linkChapter() {
        global $wgOut, $wgUser;
        $this->addSectionHeader('link_header', 'link');

        # check if we are allowed to create subpages:
        if (!($wgUser->isAllowed('edit') && $wgUser->isAllowed('createpage'))) {
            $link = $this->getLoginLink(wfMessage('login_text')->text());
            $wgOut->addHTML(getBanner(wfMessage('createpage_not_allowed', wfMessage('links')->text(),  $link)->text(),
                'createpage_not_allowed', 'grey'));
            return;
        }

        $form = new ExternalRedirectForm($this->mAction, $this->targetTitle, array(
            'desturl'   => $this->mLinkUrl,
            'desttitle' => $this->mLinkTitle,
            'destdesc'  => $this->mLinkDesc
        ));
        $form->setTitle($this->getTitle($this->targetTitle));
        if ($this->mAction != 'link') {
        }
        $form->show();
    }

    function getLoginLink($login_text) {
        global $wgTitle;
        $userLogin = SpecialPage::getTitleFor('Userlogin');
        $query =  array('returnto' => $wgTitle->getPrefixedText());

        return Linker::link($userLogin, $login_text, array(), $query);
    }

    /**
     * Wrapper-function of addWarning, used by copied code in uploadChapter()
     *
     */
    private function addWarning($msg, $id = 'warning') {
        global $wgOut;
        $wgOut->addHTML(getBanner($msg, $id, 'grey'));
    }

    /**
     * Return HTML of an error-message
     */
    protected function getError($msg) {
        return getBanner($msg, 'error', 'red');
    }

    /**
     * Return HTML of a warning-message
     */
    protected function getWarning($msg) {
        return getBanner($msg, 'error', 'grey');
    }

    /**
     * Wrapper-functions for getError, used by various copied functions
     */
    private function showUploadError($msg) {
        $this->showUploadForm($this->getUploadForm($this->getError($msg)));
    }

    private function showUploadWarning($msg) {
        $this->showUploadForm($this->getUploadForm($this->getWarning($msg)));
    }

    /**
     * This function returns the text used in the description of a newly
     * uploaded file.
     */
    protected function getInitialPageText($comment, $license, $copyrightStatus, $copyrightSource) {
        return $comment;
    }

    /**
     * Show an upload error.
     *
     * Direct copy of includes/specials/SpecialUpload.php, MW version 1.21.1
     */
    protected function showRecoverableUploadError( $message ) {
        $sessionKey = $this->mUpload->stashSession();
        $message = '<h2>' . $this->msg( 'uploaderror' )->escaped() . "</h2>\n" .
            '<div class="error">' . $message . "</div>\n";

        $form = $this->getUploadForm( $message, $sessionKey );
        $form->setSubmitText( $this->msg( 'upload-tryagain' )->escaped() );
        $this->showUploadForm( $form );
    }
}

?>
