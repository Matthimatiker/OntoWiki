<?php
/**
 * edit extension configuration via a gui
 *
 * file permissions for the folders that contain a extension needs to allow modification
 * mostly that would be 0777
 *
 * @category   OntoWiki
 * @package    OntoWiki_extensions_components_exconf
 * @author     Jonas Brekle <jonas.brekle@gmail.com>
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
class ExconfController extends OntoWiki_Controller_Component {

    const EXTENSION_CLASS = "http://ns.ontowiki.net/Extensions/Extension";

    protected $use_ftp = false;
    protected $writeable = true;

    protected $connection = null;
    protected $sftp = null;

    public function __call($method, $args) {
        echo "forward";
        $this->_forward('list');
    }
    
    public function  init() {
        parent::init();
        OntoWiki_Navigation::disableNavigation();
        $ow = OntoWiki::getInstance();
        $modMan = $ow->extensionManager;

        //determine how to write to the filesystem
        if(!is_writeable($modMan->getExtensionPath())){
            $con = $this->ftpConnect();
            if($con->connection == null){
                $this->writeable = false;
                $this->connection = false;
                $this->sftp = false;
            } else {
                $this->use_ftp = true;
                $this->connection = $con->connection;
                $this->sftp = $con->sftp;
            }
        }
    }

    function listAction() {
        $this->view->placeholder('main.window.title')->set($this->_owApp->translate->_('Configure Extensions'));

        $this->addModuleContext('main.window.exconf');
        
        $ow = OntoWiki::getInstance();
        if (!$this->_erfurt->getAc()->isActionAllowed('ExtensionConfiguration') && !$this->_request->isXmlHttpRequest()) {
            OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("config not allowed for this user", OntoWiki_Message::ERROR));
            $extensions = array();
        } else {
            //get extension from manager
            $modMan = $ow->extensionManager;
            $extensions = $modMan->getExtensions();

            //sort by name property
            $volume = array();
            foreach ($extensions as $key => $row) {
                $volume[$key]  = $row->name;
            }
            array_multisort($volume, SORT_ASC, $extensions);

            //some statistics
            $numEnabled = 0;
            $numDisabled = 0;
            foreach($extensions as $extension){
                if($extension->enabled){
                    $numEnabled++;
                } else {
                    $numDisabled++;
                }
            }
            $numAll = count($extensions);

            //save to view
            $this->view->numEnabled = $numEnabled;
            $this->view->numDisabled = $numDisabled;
            $this->view->numAll = $numAll;

            if(!is_writeable($modMan->getExtensionPath())){
                if(!$this->_request->isXmlHttpRequest()){
                    OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("the extension folder '".$modMan->getExtensionPath()."' is not writeable. no changes can be made", OntoWiki_Message::WARNING));
                }
            }
        }
        $this->view->extensions = $extensions;
    }
    
    function confAction(){
        
        OntoWiki_Navigation::disableNavigation();
        $this->view->placeholder('main.window.title')->set($this->_owApp->translate->_('Configure ').' '.$this->_request->getParam('name'));
        if (!$this->_erfurt->getAc()->isActionAllowed('ExtensionConfiguration')) {
           throw new OntoWiki_Exception("config not allowed for this user");
        } else {
            if(!isset($this->_request->name)){
                throw new OntoWiki_Exception("param 'name' needs to be passed to this action");
            }
            $ow = OntoWiki::getInstance();
            $toolbar = $ow->toolbar;
            $urlList = new OntoWiki_Url(array('controller'=>'exconf','action'=>'list'), array());
            $urlConf = new OntoWiki_Url(array('controller'=>'exconf','action'=>'conf'), array());
            $urlConf->restore = 1;
            $toolbar->appendButton(OntoWiki_Toolbar::SUBMIT, array('name' => 'save'))
                    ->appendButton(OntoWiki_Toolbar::CANCEL, array('name' => 'back', 'class' => '', 'url' => (string) $urlList))
                    ->appendButton(OntoWiki_Toolbar::EDIT, array('name' => 'restore defaults', 'class' => '', 'url' => (string) $urlConf));

            // add toolbar
            $this->view->placeholder('main.window.toolbar')->set($toolbar);

            $name = $this->_request->getParam('name');
            $manager        = $ow->extensionManager;
            $dirPath  = $manager->getExtensionPath(). $name .'/';
            if(!is_dir($dirPath)){
                throw new OntoWiki_Exception("invalid extension - does not exists");
            }
            $configFilePath = $dirPath.Ontowiki_Extension_Manager::DEFAULT_CONFIG_FILE;
            $localIniPath   = $manager->getExtensionPath().$name.".ini";

            $privateConfig       = $manager->getPrivateConfig($name);
            $config              = ($privateConfig != null ? $privateConfig->toArray() : array());
            $this->view->enabled = $manager->isExtensionActive($name);

            $this->view->config  = $config;
            $this->view->name    = $name;

            if(!is_writeable($manager->getExtensionPath())){
                if(!$this->_request->isXmlHttpRequest()){
                    OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("the extension folder '".$manager->getExtensionPath()."' is not writeable. no changes can be made", OntoWiki_Message::WARNING));
                }
            } else  {
                    //react on post data
                    if(isset($this->_request->remove)){
                        if(rmdir($dirPath)){
                            $this->_redirect($this->urlBase.'exconf/list');
                        } else {
                            OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("extension could not be deleted", OntoWiki_Message::ERROR));
                        }
                    }
                    if(isset($this->_request->enabled)){
                        if(!file_exists($localIniPath)){
                            @touch($localIniPath);
                        }
                        $ini = new Zend_Config_Ini($localIniPath, null, array('allowModifications' => true));
                        $ini->enabled = $this->_request->getParam('enabled') == "true";
                        $writer = new Zend_Config_Writer_Ini(array());
                        $writer->write($localIniPath, $ini, true);
                    }
                    if(isset($this->_request->config)){
                        $arr = json_decode($this->_request->getParam('config'), true);
                        if($arr == null){
                            throw new OntoWiki_Exception("invalid json: ".$this->_request->getParam('config'));
                        } else {
                            //only modification of the private section and the enabled-property are allowed
                            foreach($arr as $key => $val){
                                if($key != 'enabled' && $key != 'private'){
                                    unset($arr[$key]);
                                }
                            }
                            $writer = new Zend_Config_Writer_Ini(array());
                            $postIni = new Zend_Config($arr, true);
                            $writer->write($localIniPath, $postIni, true);
                            OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("config sucessfully changed", OntoWiki_Message::SUCCESS));
                        }
                        $this->_redirect($this->urlBase.'exconf/conf/?name='.$name);
                    }
                    if(isset($this->_request->reset)){
                        if(@unlink($localIniPath)){
                            OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("config sucessfully reverted to default", OntoWiki_Message::SUCCESS));
                        } else {
                            OntoWiki::getInstance()->appendMessage(new OntoWiki_Message("config not reverted to default - not existing or not writeable", OntoWiki_Message::ERROR));
                        }
                        $this->_redirect($this->urlBase.'exconf/conf/?name='.$name);
                    }
            }
        }

        if($this->_request->isXmlHttpRequest()){
            //no rendering
            exit;
        }
    }

    public function explorerepoAction(){
        $repoUrl = $this->_privateConfig->repoUrl;
        if(($otherRepo = $this->getParam("repoUrl")) != null){
            $repoUrl = $otherRepo;
        }
        $graph = $this->_privateConfig->graph;
        if(($otherRepo = $this->getParam("repoUrl")) != null){
            $graph = $otherRepo;
        }
        $this->view->repoUrl = $repoUrl;
        $adapter = new Erfurt_Store_Adapter_Sparql(array("serviceurl"=>$repoUrl, 'graphs'=>array('')));
        $store = new Erfurt_Store(array("adapterInstance"=>$adapter), "sparql");
        $rdfGraphObj = new Erfurt_Rdf_Model($graph);

        $listHelper = Zend_Controller_Action_HelperBroker::getStaticHelper('List');
        $listName = "extension";
        if($listHelper->listExists($listName)){
            $list = $listHelper->getList($listName);
            $listHelper->addList($listName, $list, $this->view);
        } else {
            if($this->_owApp->selectedModel == null){
                $this->_owApp->appendMessage(new OntoWiki_Message("your session timed out",  OntoWiki_Message::ERROR));
                $this->_redirect($this->_config->baseUrl);
            }
            $list = new OntoWiki_Model_Instances($store, $rdfGraphObj, array());
            $list->addTypeFilter(self::EXTENSION_CLASS);
            $listHelper->addListPermanently($listName, $list, $this->view);
        }
    }

    public function installarchiveremoteAction(){
        $url = "http://pop-imap-troubleshooter.googlecode.com/files/pop-imap-troubleshooter-2.0.1.tar.gz"; //test
        $parsedUrl = parse_url($url);
        $values = explode("/", $parsedUrl['path']);
        if($values != false){
            $new_values = array();
            foreach($values as $v) {
                if(!empty($v)) $new_values[]= $v;
            }
            $filename = $new_values[count($new_values)-1];
            $fileStr = file_get_contents($url);
            if($fileStr != false){
                $tmp = sys_get_temp_dir();
                if(!(substr($tmp, -1) == PATH_SEPARATOR)){
                    $tmp .= PATH_SEPARATOR;
                }
                $tmpfname = tempnam($tmp, $filename);

                $localFilehandle = fopen($tmpfname, "w+");
                fwrite($localFilehandle, $fileStr);
                rewind($localFilehandle);
                
                $this->installArchive($filename, $localFilehandle);
                fclose($localFilehandle); //deletes file
            }
        }
    }

    public function archiveuploadformAction(){
        $this->view->placeholder('main.window.title')->set('Upload new extension archive');
        $this->view->formActionUrl = $this->_config->urlBase . 'exconf/installarchiveupload';
        $this->view->formEncoding  = 'multipart/form-data';
        $this->view->formClass     = 'simple-input input-justify-left';
        $this->view->formMethod    = 'post';
        $this->view->formName      = 'archiveupload';

        $toolbar = $this->_owApp->toolbar;
        $toolbar->appendButton(OntoWiki_Toolbar::SUBMIT, array('name' => 'Upload Archive', 'id' => 'archiveupload'))
                ->appendButton(OntoWiki_Toolbar::RESET, array('name' => 'Cancel', 'id' => 'archiveupload'));
        $this->view->placeholder('main.window.toolbar')->set($toolbar);

    }

    public function installarchiveuploadAction(){
        if ($_FILES['archive_file']['error'] == UPLOAD_ERR_OK) {
            // upload ok, move file
            //$fileUri  = $this->_request->getPost('file_uri');
            $fileName = $_FILES['archive_file']['name'];
            $tmpName  = $_FILES['archive_file']['tmp_name'];
            $mimeType = $_FILES['archive_file']['type'];
            $localFilehandle = fopen($tmpName, 'r');
            $this->installArchive($tmpName, $localFilehandle);
            fclose($localFilehandle);
        } else {echo "error";}
    }

    protected function installArchive($name, $fileHandle){
        require_once 'Archive.php';
        $ext = mime_content_type($name);
        switch ($ext){
            case "application/zip":
                $archive = new zip_file($name);
                break;
            case "application/x-bzip2":
                $archive = new bzip_file($name);
                break;
            case "application/x-gzip":
                $archive = new gzip_file($name);
                break;
            case "application/x-tar":
                $archive = new tar_file($name);
                break;
        }
        // Overwrite existing files
        $ow = OntoWiki::getInstance();
        $modMan = $ow->extensionManager;
        $path = $modMan->getExtensionPath();
        $archive->set_options(array('overwrite' => 1, 'basedir' => $path));
        // Extract contents of archive to disk
        $archive->extract_files();
    }

    protected function checkForUpdates(){

    }

    /**
     * Get the connection to ftp-server
     *
     * @param unknown_type $sftp
     * @param unknown_type $connection
     */
    public function ftpConnect(){
        if(isset($this->_privateConfig->ftp)){
            $username = $this->_privateConfig->ftp->username;
            $password = $this->_privateConfig->ftp->password;
            $hostname = $this->_privateConfig->ftp->hostname;
            $ssh2 = "ssh2.sftp://$username:$password@$hostname:22";
            $connection = ssh2_connect("$hostname", 22);
            ssh2_auth_password($connection, $username, $password);
            $sftp = ssh2_sftp($connection);

            $ret = new stdClass();
            $ret->connection = $connection;
            $ret->sftp = $sftp;
            return $ret;
        } else {
            $ret = new stdClass();
            $ret->connection = null;
            $ret->sftp = null;
            return $ret;
        }
    }
}

