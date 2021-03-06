<?php

namespace Backend\Modules\Extensions\Actions;

use Backend\Core\Engine\Base\ActionIndex as BackendBaseActionIndex;
use Backend\Core\Engine\Language as BL;
use Backend\Core\Engine\Authentication as BackendAuthentication;
use Backend\Core\Engine\Model as BackendModel;
use Backend\Core\Engine\DataGridArray as BackendDataGridArray;
use Backend\Modules\Extensions\Engine\Model as BackendExtensionsModel;

/**
 * This is the detail-action it will display the details of a module.
 */
class DetailModule extends BackendBaseActionIndex
{
    /**
     * Module we request the details of.
     *
     * @var string
     */
    private $currentModule;

    /**
     * Datagrids.
     *
     * @var BackendDataGridArray
     */
    private $dataGridCronjobs;
    private $dataGridEvents;

    /**
     * Information fetched from the info.xml.
     *
     * @var array
     */
    private $information = array();

    /**
     * List of warnings.
     *
     * @var	array
     */
    private $warnings = array();

    /**
     * Execute the action.
     */
    public function execute()
    {
        // get parameters
        $this->currentModule = $this->getParameter('module', 'string');

        // does the item exist
        if ($this->currentModule !== null && BackendExtensionsModel::existsModule($this->currentModule)) {
            // call parent, this will probably add some general CSS/JS or other required files
            parent::execute();

            // load data
            $this->loadData();

            // load datagrids
            $this->loadDataGridCronjobs();
            $this->loadDataGridEvents();

            // parse
            $this->parse();

            // display the page
            $this->display();
        } else {
            // no item found, redirect to index, because somebody is fucking with our url
            $this->redirect(BackendModel::createURLForAction('Modules') . '&error=non-existing');
        }
    }

    /**
     * Load the data.
     * This will also set some warnings if needed.
     */
    private function loadData()
    {
        // inform that the module is not installed yet
        if (!BackendModel::isModuleInstalled($this->currentModule)) {
            $this->warnings[] = array('message' => BL::getMessage('InformationModuleIsNotInstalled'));
        }

        // fetch the module information
        $moduleInformation = BackendExtensionsModel::getModuleInformation($this->currentModule);
        $this->information = $moduleInformation['data'];
        $this->warnings = $this->warnings + $moduleInformation['warnings'];
    }

    /**
     * Load the data grid which contains the cronjobs.
     */
    private function loadDataGridCronjobs()
    {
        // no cronjobs = don't bother
        if (!isset($this->information['cronjobs'])) {
            return;
        }

        // create data grid
        $this->dataGridCronjobs = new BackendDataGridArray($this->information['cronjobs']);

        // hide columns
        $this->dataGridCronjobs->setColumnsHidden(array('minute', 'hour', 'day-of-month', 'month', 'day-of-week', 'action', 'description', 'active'));

        // add cronjob data column
        $this->dataGridCronjobs->addColumn(
            'cronjob',
            BL::getLabel('Cronjob'),
            '[description]<br /><strong>[minute] [hour] [day-of-month] [month] [day-of-week]</strong> php ' . PATH_WWW . '/backend/cronjob module=<strong>' . $this->currentModule . '</strong> action=<strong>[action]</strong>',
            null,
            null,
            null,
            0
        );

        // no paging
        $this->dataGridCronjobs->setPaging(false);
    }

    /**
     * Load the data grid which contains the events.
     */
    private function loadDataGridEvents()
    {
        // no hooks = don't bother
        if (!isset($this->information['events'])) {
            return;
        }

        // create data grid
        $this->dataGridEvents = new BackendDataGridArray($this->information['events']);

        // no paging
        $this->dataGridEvents->setPaging(false);
    }

    /**
     * Parse.
     */
    protected function parse()
    {
        parent::parse();

        // assign module data
        $this->tpl->assign('name', $this->currentModule);
        $this->tpl->assign('warnings', $this->warnings);
        $this->tpl->assign('information', $this->information);
        $this->tpl->assign('showInstallButton', !BackendModel::isModuleInstalled($this->currentModule) && BackendAuthentication::isAllowedAction('InstallModule'));

        // data grids
        $this->tpl->assign('dataGridEvents', (isset($this->dataGridEvents) && $this->dataGridEvents->getNumResults() > 0) ? $this->dataGridEvents->getContent() : false);
        $this->tpl->assign('dataGridCronjobs', (isset($this->dataGridCronjobs) && $this->dataGridCronjobs->getNumResults() > 0) ? $this->dataGridCronjobs->getContent() : false);
    }
}
