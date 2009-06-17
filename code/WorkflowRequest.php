<?php
/**
 * A "workflow request" represents a full review process for one set of changes to a single page. 
 * Only one workflow request can be active for any given page; however, a page may have a number 
 * of historical, closed workflow requests.
 * 
 * The WorkflowRequest object shouldn't be directly edited.  Instead, you call "workflow step"
 * methods on the object, that will update the object appropriately.
 * 
 * To create or retrieve a WorkflowRequest object, call {@link SiteTreeCMSWorkflow::openOrNewWorkflowRequest()}
 * or {@link SiteTreeCMSWorkflow::openWorkflowRequest()} on the relevant {@link SiteTree} object.
 *
 * The following examples show how a workflow can be created.
 *
 * Request publication:
 * <code>
 * $wf = $page->openOrNewWorkflowRequest('WorkflowPublicationRequest')
 * $wf->request("Can you please publish this page");
 * </code>
 * 
 * Reject changes:
 * <code>
 * $wf = $page->openWorkflowRequest()
 * $wf->deny("It's not acceptable.  Please correct the spelling.");
 * </code>
 * 
 * Approve changes:
 * <code>
 * $wf = $page->openWorkflowRequest()
 * $wf->approve("Thanks, looks good now");
 * </code>
 * 
 * {@link WorkflowRequest::Changes()} will provide a list of the changes that the workflow has gone through,
 * suitable for presentation as a discussion thread attached to the page.
 * 
 * @package cmsworkflow
 */
class WorkflowRequest extends DataObject implements i18nEntityProvider {
	
	static $db = array(
		// @todo AwaitingReview
		'Status' => "Enum('AwaitingApproval,Approved,Denied,AwaitingEdit','AwaitingApproval')"
	);
	
	static $has_one = array(
		'Author' => 'Member',
		'Publisher' => 'Member', // see SiteTreeCMSWorkflow->onBeforeWrite()
		'Page' => 'SiteTree'
	);
	
	static $has_many = array(
		'Changes' => 'WorkflowRequestChange', // see WorkflowRequest->onBeforeWrite()
	);
	
	static $many_many = array(
		'Publishers' => 'Member'
	);
	
	/**
	 * Factory method setting up a new WorkflowRequest with associated
	 * state. Sets relations to publishers and authors, 
	 * 
	 * @param SiteTree $page
	 * @param Member $member The user requesting publication
	 * @param DataObjectSet $publishers Publishers assigned to this request.
	 * @return boolean|WorkflowPublicationRequest
	 */
	public static function create_for_page($page, $author = null, $publishers = null) {
		user_error('WorkflowRequest::create_for_page() - Abstract method, please implement in subclass', E_USER_ERROR);
	}
	
	/*
	function onBeforeWrite() {
		// if the request status has changed, we track it through a separate relation
		$changedFields = $this->getChangedFields();
		// only write if the status has changed, and wasn't previously NULL (in which case onAfterWrite() takes over)
		if((isset($changedFields['Status']) && $changedFields['Status']['after'] && $changedFields['Status']['before'])) {
			$change = $this->addNewChange();
		}
		
		// see onAfterWrite() for creation of the first change when the request is initiated
		
		parent::onBeforeWrite();
	}
	
	function onAfterWrite() {
		// if request has no changes (= was just created),
		// add a new change. this is necessary because we don't
		// have the required WorkflowRequestID in the first call
		// to onBeforeWrite()
		if(!$this->Changes()->Count()) {
			$change = $this->addNewChange();
		}
		
		parent::onAfterWrite();
	}
	*/

	/**
	 * Approve this request, notify interested parties
	 * and close it. Used by {@link LeftAndMainCMSWorkflow}
	 * and {@link SiteTreeCMSWorkflow}.
	 * 
	 * @param Member $author
	 * @return boolean
	 */
	public function request($comment, $member = null) {
		if(!$member) $member = Member::currentUser();

		$this->Status = 'AwaitingApproval';
		$this->write();

		$this->addNewChange($comment, $this->Status, $member);
		$this->notifyAwaitingApproval($comment);
		
		return true;
	}
	
	/**
	 * Approve this request, notify interested parties
	 * and close it. Used by {@link LeftAndMainCMSWorkflow}
	 * and {@link SiteTreeCMSWorkflow}.
	 * 
	 * @param Member $author
	 * @return boolean
	 */
	public function approve($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->Page()->canPublish($member)) {
			return false;
		}
		
		$this->PublisherID = $member->ID;
		$this->write();
		// open the request and notify interested parties
		$this->Status = 'Approved';
		$this->write();

		$this->addNewChange($comment, $this->Status, $member);
		if($notify) $this->notifyApproved($comment);
		
		return true;
	}
	
	/**
	 * Comment on a workflow item without changing the status
	 */
	public function comment($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->Page()->canEdit($member) && !$this->Page()->canPublish($member)) {
			return false;
		}
		$this->addNewChange($comment, null, $member);
		if($notify) $this->notifyComment($comment);
		return true;
	}

	/**
	 * Request an edit to this page before it can be published.
	 * 
	 * @param Member $author
	 * @return boolean
	 */
	public function requestedit($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->Page()->canPublish($member)) {
			return false;
		}
		
		// "publisher" in this sense means "deny-author"
		$this->PublisherID = $member->ID;
		$this->write();
		// open the request and notify interested parties
		$this->Status = 'AwaitingEdit';
		$this->write();

		$this->addNewChange($comment, $this->Status, $member);
		if($notify) $this->notifyAwaitingEdit($comment);
		
		return true;
	}
	
	/**
	 * Deny this request, notify interested parties
	 * and close it. Used by {@link LeftAndMainCMSWorkflow}
	 * and {@link SiteTreeCMSWorkflow}.
	 * 
	 * @param Member $author
	 * @return boolean
	 */
	public function deny($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->Page()->canPublish($member)) {
			return false;
		}
		
		// "publisher" in this sense means "deny-author"
		$this->PublisherID = $member->ID;
		$this->write();
		// open the request and notify interested parties
		$this->Status = 'Denied';
		$this->write();

		// revert page to live (which might undo independent changes by other authors)
		$this->Page()->doRevertToLive();

		$this->addNewChange($comment, $this->Status, $member);
		if($notify) $this->notifyDenied($comment);
		
		return true;
	}
	
	/**
	 * Create a new {@link WorkflowRequestChange} with the current
	 * page status and versions, and link it to this object.
	 *
	 * @return WorkflowRequestChange
	 */
	protected function addNewChange($comment, $status, $member) {
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		
		$change = new WorkflowRequestChange();
		$change->AuthorID = $member->ID;
		$change->Status = $status;
		$change->Comment = $comment;
		
		$page = $this->Page();
		$draftPage = Versioned::get_one_by_stage('SiteTree', 'Draft', "{$bt}SiteTree{$bt}.{$bt}ID{$bt} = $page->ID", false, "Created DESC");
		// draftpage might not exist for pages "deleted from stage"
		if($draftPage) $change->PageDraftVersion = $draftPage->Version;
		$livePage = Versioned::get_one_by_stage('SiteTree', 'Live', "{$bt}SiteTree{$bt}.{$bt}ID{$bt} = $page->ID", false, "Created DESC");
		// livepage might not exist for pages which have never been published
		if($livePage) $change->PageLiveVersion = $livePage->Version;
		$change->write();
		$this->Changes()->add($change);
		
		return $change;
	}
	
	function getCMSFields() {
		$fields = parent::getCMSFields();
		
		$diffLinkTitle = _t('SiteTreeCMSWorkflow.DIFFERENCESLINK', 'Show differences to live');
		
		$tf = $fields->dataFieldByName('Changes');
		$tf->setFieldList(array(
			'Created' => $this->fieldLabel('Created'), 
			'Author.Title' => $this->fieldLabel('Author'), 
			'Comment' => $this->fieldLabel('Comment'), 
			'StatusDescription' => $this->fieldLabel('Status'), 
			'DiffLinkToLastPublished' => _t('SiteTreeCMSWorkflow.DIFFERENCESTOLIVECOLUMN', 'Differences to live'),
			'DiffLinkToPrevious' => _t('SiteTreeCMSWorkflow.DIFFERENCESTHISCHANGECOLUMN', 'Differences in this change'),
		));
		$tf->setFieldCasting(array(
			'Created' => 'Date->Nice'
		));
		$tf->setFieldFormatting(array(
			"DiffLinkToLastPublished" => '<a href=\"$value\" target=\"_blank\" class=\"externallink\">Show</a>',
			"DiffLinkToPrevious" => '<a href=\"$value\" target=\"_blank\" class=\"externallink\">Show</a>'
		));
		$fields->replaceField(
			'Status',
			new ReadonlyField('StatusDescription', $this->fieldLabel('Status'), $this->StatusDescription)
		);
		
		return $fields;
	}
	
	function getCMSDetailFields() {
		$fields = $this->getFrontEndFields();
		$fields->insertBefore(
			$titleField = new ReadonlyField(
				'RequestTitleField',
				$this->fieldLabel('Title'),
				$this->getTitle()
			),
			'Status'
		);
		$fields->push(
			$showDifferencesField = new ReadonlyField(
				'ShowDifferencesLink',
				false,
				sprintf(
					'<a href="%s">%s</a>', 
					$this->DiffLinkToLastPublished,
					_t('SiteTreeCMSWorkflow.DIFFERENCESTOLIVECOLUMN', 'Differences to live')
				)
			)
		);
		$showDifferencesField->dontEscape = true;
		$fields->replaceField(
			'Status',
			new ReadonlyField(
				'StatusDescription', 
				$this->fieldLabel('Status'), 
				$this->StatusDescription
			)
		);
		
		return $fields;
	}
	
	/**
	 * Notify any publishers assigned to this page when a new request
	 * is lodged.
	 */
	public function notifyAwaitingApproval($comment) {
		$publishers = $this->Page()->PublisherMembers();
		$author = $this->Author();

		foreach($publishers as $publisher){
			$this->sendNotificationEmail(
				$author, // sender
				$publisher, // recipient
				_t("{$this->class}.EMAIL_SUBJECT_AWAITINGAPPROVAL"),
				_t("{$this->class}.EMAIL_PARA_AWAITINGAPPROVAL"),
				$comment,
				'WorkflowGenericEmail'
			);
		}
	}
	
	/**
	 * Notify the author of a request once a page has been approved (=published).
	 */
	public function notifyApproved($comment) {
		$publisher = Member::currentUser();
		$author = $this->Author();
		$subject = sprintf(
			_t("{$this->class}.EMAIL_SUBJECT_APPROVED"),
			$this->Page()->Title
		);

		$this->sendNotificationEmail(
			$publisher, // sender
			$author, // recipient
			_t("{$this->class}.EMAIL_SUBJECT_APPROVED"),
			_t("{$this->class}.EMAIL_PARA_APPROVED"),
			$comment,
			'WorkflowGenericEmail'
		);
	}
	
	function notifyDenied($comment) {
		$publisher = Member::currentUser();
		$author = $this->Author();

		$this->sendNotificationEmail(
			$publisher, // sender
			$author, // recipient
			_t("{$this->class}.EMAIL_SUBJECT_DENIED"),
			_t("{$this->class}.EMAIL_PARA_DENIED"),
			$comment,
			'WorkflowGenericEmail'
		);
	}

	function notifyAwaitingEdit($comment) {
		$publisher = Member::currentUser();
		$author = $this->Author();

		$this->sendNotificationEmail(
			$publisher, // sender
			$author, // recipient
			_t("{$this->class}.EMAIL_SUBJECT_AWAITINGEDIT"),
			_t("{$this->class}.EMAIL_PARA_AWAITINGEDIT"),
			$comment,
			'WorkflowGenericEmail'
		);
	}


	function notifyComment($comment) {
		// Comment recipients cover everyone except the person making the comment
		$commentRecipients = array();
		if(Member::currentUserID() != $this->Author()->ID) $commentRecipients[] = $this->Author();
		$publishers = $this->Page()->PublisherMembers();
		foreach($publishers as $publisher){
			if(Member::currentUserID() != $publisher->ID) $commentRecipients[] = $publisher;
		}

		foreach($commentRecipients as $recipient) {
			$this->sendNotificationEmail(
				Member::currentUser(), // sender
				$recipient, // recipient
				_t("{$this->class}.EMAIL_SUBJECT_COMMENT"),
				_t("{$this->class}.EMAIL_PARA_COMMENT"),
				$comment,
				'WorkflowGenericEmail'
			);
		}
	}
	
	protected function sendNotificationEmail($sender, $recipient, $subjectTemplate, $paragraphTemplate, $comment, $template = null) {
		if(!$template) {
			$template = 'WorkflowGenericEmail';
		}
		
		
		$subject = sprintf($subjectTemplate, 
				$this->Page()->Title);

		$paragraph = sprintf($paragraphTemplate, 
				$sender->FirstName . ' ' . $sender->Surname,
				$this->Page()->Title);
		
		$email = new Email();
		$email->setTo($recipient->Email);
		$email->setFrom(($sender->Email) ? $sender->Email : Email::getAdminEmail());
		$email->setTemplate($template);
		$email->setSubject($subject);
		$email->populateTemplate(array(
			"PageCMSLink" => "admin/show/".$this->Page()->ID,
			"Recipient" => $recipient,
			"Sender" => $sender,
			"Page" => $this->Page(),
			"StageSiteLink"	=> $this->Page()->Link()."?stage=stage",
			"LiveSiteLink"	=> $this->Page()->Link()."?stage=live",
			"Workflow" => $this,
			"Comment" => $comment,
			"Paragraph" => $paragraph,
		));
		return $email->send();
	}
	
	/**
	 * Returns a {@link DataDifferencer} object representing the changes.
	 */
	public function Diff() {
		$diff = new DataDifferencer($this->fromRecord(), $this->toRecord());
		$diff->ignoreFields('AuthorID', 'LastEdited', 'Status');
		return $diff;
	}
	
	/**
	 * Returns the old record that will be replaced by this publication.
	 */
	public function fromRecord() {
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		return Versioned::get_one_by_stage('SiteTree', 'Live', "{$bt}SiteTree_Live{$bt}.ID = {$this->PageID}", true, "Created DESC");
	}
	
	/**
	 * Returns the new record for which publication is being requested.
	 */
	public function toRecord() {
		return $this->Page();
	}
	
	/**
	 * Is the workflow request still pending.
	 * Important for creation of new workflow requests
	 * as there should be only one open request
	 * per page at any given point in time.
	 * 
	 * @return boolean
	 */
	public function isOpen() {
		return (!in_array($this->Status,array('Approved','Denied')));
	}
	
	/**
	 * Returns a CMS link to see differences made in the request
	 * 
	 * @return string URL
	 */
	protected function getDiffLinkToLastPublished() {
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		$page = $this->Page();
		$fromVersion = $page->Version;
		$latestPublished = Versioned::get_one_by_stage($page->class, 'Live', "{$bt}SiteTree_Live{$bt}.ID = {$page->ID}", true, "Created DESC");
		if(!$latestPublished) return false;
		
		return "admin/compareversions/$page->ID/?From={$fromVersion}&To={$latestPublished->Version}";
	}
	
	/**
	 * Determines if a request can be created by an author for a specific page.
	 * Add custom authentication checks by subclassing this method.
	 * 
	 * @param Member $member
	 * @param SiteTree $page
	 * @return boolean
	 */
	public static function can_create($member = NULL, $page) {
		if(!$member && $member !== FALSE) {
			$member = Member::currentUser();
		}

		return $page->canEdit($member);
	}
	
	/**
	 * Get all publication requests by a specific author
	 * 
	 * @param Member $author
	 * @return DataObjectSet
	 */
	public static function get_by_author($class, $author, $status = null) {
		// To ensure 2.3 and 2.4 compatibility
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		
		if($status) $statusStr = implode(',', $status);

		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		
		// build filter
		$filter = "{$bt}Member{$bt}.ID = {$author->ID}  
			AND {$bt}WorkflowRequest{$bt}.ClassName IN ('$classesSQL')
		";
		if($status) {
			$filter .= "AND {$bt}WorkflowRequest{$bt}.Status IN ('" . Convert::raw2sql($statusStr) . "')";
		}
		
		return DataObject::get(
			"SiteTree", 
			$filter, 
			"{$bt}SiteTree{$bt}.{$bt}LastEdited{$bt} DESC",
			"LEFT JOIN {$bt}WorkflowRequest{$bt} ON {$bt}WorkflowRequest{$bt}.PageID = {$bt}SiteTree{$bt}.ID " .
			"LEFT JOIN {$bt}Member{$bt} ON {$bt}Member{$bt}.ID = {$bt}WorkflowRequest{$bt}.AuthorID"
		);
	}
	
	/**
	 * Get all publication requests assigned to a specific publisher
	 * 
	 * @param string $class WorkflowRequest subclass
	 * @param Member $publisher
	 * @param array $status One or more stati from the $Status property
	 * @return DataObjectSet
	 */
	public static function get_by_publisher($class, $publisher, $status = null) {
		// To ensure 2.3 and 2.4 compatibility
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";

		if($status) $statusStr = implode(',', $status);

		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		
		// build filter
		$filter = "{$bt}WorkflowRequest_Publishers{$bt}.MemberID = {$publisher->ID} 
			AND {$bt}WorkflowRequest{$bt}.ClassName IN ('$classesSQL')
		";
		if($status) {
			$filter .= "AND {$bt}WorkflowRequest{$bt}.Status IN ('" . Convert::raw2sql($statusStr) . "')";
		} 
		
		return DataObject::get(
			"SiteTree", 
			$filter, 
			"{$bt}SiteTree{$bt}.{$bt}LastEdited{$bt} DESC",
			"LEFT JOIN {$bt}WorkflowRequest{$bt} ON {$bt}WorkflowRequest{$bt}.PageID = {$bt}SiteTree{$bt}.ID " .
			"LEFT JOIN {$bt}WorkflowRequest_Publishers{$bt} ON {$bt}WorkflowRequest{$bt}.ID = {$bt}WorkflowRequest_Publishers{$bt}.WorkflowRequestID"
		);
	}
	
	/**
	 * Get publication requests from all users
	 * @param string $class WorkflowRequest subclass
	 * @param array $status One or more stati from the $Status property
	 * @return DataObjectSet
	 */
	public static function get($class, $status = null) {
		// To ensure 2.3 and 2.4 compatibility
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";

		if($status) $statusStr = implode(',', $status);

		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		
		// build filter
		$filter = "{$bt}WorkflowRequest{$bt}.ClassName IN ('$classesSQL')";
		if($status) {
			$filter .= "AND {$bt}WorkflowRequest{$bt}.Status IN ('" . Convert::raw2sql($statusStr) . "')";
		} 
		
		return DataObject::get(
			"SiteTree", 
			$filter, 
			"{$bt}SiteTree{$bt}.{$bt}LastEdited{$bt} DESC",
			"LEFT JOIN {$bt}WorkflowRequest{$bt} ON {$bt}WorkflowRequest{$bt}.PageID = {$bt}SiteTree{$bt}.ID"
		);
	}
	
	/**
	 * @return string
	 */
	public function getTitle() {
		$title = _t("{$this->class}.TITLE");
		if(!$title) $title = _t('WorkflowRequest.TITLE');
		
		return $title;
	}
	
	/**
	 * @return string Translated $Status property
	 */
	public function getStatusDescription() {
		return self::get_status_description($this->Status);
	}
	
	public static function get_status_description($status) {
		switch($status) {
			case 'Open':
				return _t('SiteTreeCMSWorkflow.STATUS_OPEN', 'Open');
			case 'Approved':
				return _t('SiteTreeCMSWorkflow.STATUS_APPROVED', 'Approved');
			case 'AwaitingApproval':
				return _t('SiteTreeCMSWorkflow.STATUS_AWAITINGAPPROVAL', 'Awaiting Approval');
			case 'AwaitingEdit':
				return _t('SiteTreeCMSWorkflow.STATUS_AWAITINGEDIT', 'Awaiting Edit');
			case 'Denied':
				return _t('SiteTreeCMSWorkflow.STATUS_DENIED', 'Denied');
			default:
				return _t('SiteTreeCMSWorkflow.STATUS_UNKNOWN', 'Unknown');
		}
	}
	
	function fieldLabels() {
		$labels = parent::fieldLabels();
		
		$labels['Status'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_STATUS', "Status");
		$labels['Author'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_AUTHOR', "Author");
		$labels['Publisher'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_PUBLISHER', "Publisher");
		$labels['Page'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_PAGE', "Page");
		$labels['Publishers'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_PUBLISHERS', "Publishers");
		
		return $labels;
	}
	
	function provideI18nEntities() {
		$entities = array();
		$entities['WorkflowRequest.EMAIL_SUBJECT_GENERIC'] = array(
			"The workflow status of the \"%s\" page has changed",
			PR_MEDIUM,
			'Email subject with page title'
		);
		$entities['WorkflowRequest.TITLE'] = array(
			"Workflow Request",
			PR_MEDIUM,
			'Title for this request, shown e.g. in the workflow status overview for a page'
		);
		
		return $entities;
	}
	
	/**
	 * Return the actions that can be performed on this workflow request.
	 * @return array The key is a LeftAndMainCMSWorkflow action, and the value is a label
	 * for the buton.
	 * @todo There's not a good separation between model and control in this stuff.
	 */
	function WorkflowActions() {
		$actions = array();
		
		if($this->Status == 'AwaitingApproval' && $this->Page()->canPublish()) {
			$actions['cms_approve'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_APPROVE", "Approve");
			$actions['cms_requestedit'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_REQUESTEDIT", "Request edit");
			
		} else if($this->Status == 'AwaitingEdit' && $this->Page()->canEdit()) {
			// @todo this couples this class to its subclasses. :-(
			$requestAction = (get_class($this) == 'WorkflowDeletionRequest') ? 'cms_requestdeletefromlive' : 'cms_requestpublication';
			$actions[$requestAction] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_RESUBMIT", "Re-submit");
		}
		
		$actions['cms_comment'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_COMMENT", "Comment");
		$actions['cms_deny'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_DENY","Deny/cancel");
		return $actions;
	}

}
?>