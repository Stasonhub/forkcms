<?php

namespace Backend\Modules\Faq\Actions;

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use Backend\Core\Engine\Base\ActionIndex as BackendBaseActionIndex;
use Backend\Core\Engine\Authentication as BackendAuthentication;
use Backend\Core\Engine\DataGridArray as BackendDataGridArray;
use Backend\Core\Engine\DataGridDB as BackendDataGridDB;
use Backend\Core\Engine\Language as BL;
use Backend\Core\Engine\Model as BackendModel;
use Backend\Modules\Faq\Engine\Model as BackendFaqModel;

/**
 * This is the index-action (default), it will display the overview
 */
class Index extends BackendBaseActionIndex
{
    /**
     * The dataGrids
     *
     * @var	array
     */
    private $dataGrids;

    /**
     * Default dataGird
     *
     * @var BackendDataGridArray
     */
    private $emptyDatagrid;

    /**
     * Execute the action
     */
    public function execute()
    {
        parent::execute();
        $this->loadDatagrids();

        $this->parse();
        $this->display();
    }

    /**
     * Loads the dataGrids
     */
    private function loadDatagrids()
    {
        // load all categories
        $categories = BackendFaqModel::getCategories(true);

        // loop categories and create a dataGrid for each one
        foreach ($categories as $categoryId => $categoryTitle) {
            $dataGrid = new BackendDataGridDB(
                BackendFaqModel::QRY_DATAGRID_BROWSE,
                array(BL::getWorkingLanguage(), $categoryId)
            );
            $dataGrid->enableSequenceByDragAndDrop();
            $dataGrid->setColumnsHidden(array('category_id', 'sequence'));
            $dataGrid->setColumnAttributes('question', array('class' => 'title'));
            $dataGrid->setRowAttributes(array('id' => '[id]'));

            // check if this action is allowed
            if (BackendAuthentication::isAllowedAction('Edit')) {
                $dataGrid->setColumnURL('question', BackendModel::createURLForAction('Edit') . '&amp;id=[id]');
                $dataGrid->addColumn(
                    'edit',
                    null,
                    BL::lbl('Edit'),
                    BackendModel::createURLForAction('Edit') . '&amp;id=[id]',
                    BL::lbl('Edit')
                );
            }

            // add dataGrid to list
            $this->dataGrids[] = array(
                'id' => $categoryId,
                'title' => $categoryTitle,
                'content' => $dataGrid->getContent(),
            );
        }

        // set empty datagrid
        $this->emptyDatagrid = new BackendDataGridArray(
            array(array(
                'dragAndDropHandle' => '',
                'question' => BL::msg('NoQuestionInCategory'),
                'edit' => '',
            ))
        );
        $this->emptyDatagrid->setAttributes(array('class' => 'table table-hover table-striped fork-data-grid jsDataGrid sequenceByDragAndDrop emptyGrid'));
        $this->emptyDatagrid->setHeaderLabels(array('edit' => null, 'dragAndDropHandle' => null));
    }

    /**
     * Parse the dataGrids and the reports
     */
    protected function parse()
    {
        parent::parse();

        // parse dataGrids
        if (!empty($this->dataGrids)) {
            $this->tpl->assign('dataGrids', $this->dataGrids);
        }
        $this->tpl->assign('emptyDatagrid', $this->emptyDatagrid->getContent());
    }
}
