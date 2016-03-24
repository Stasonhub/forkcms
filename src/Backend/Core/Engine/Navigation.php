<?php

namespace Backend\Core\Engine;

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Backend\Core\Engine\Model as BackendModel;

/**
 * This class will be used to build the navigation
 *
 * @author Tijs Verkoyen <tijs@sumocoders.be>
 * @author Dave Lens <dave.lens@netlash.com>
 * @author Davy Hellemans <davy.hellemans@netlash.com>
 * @author Dieter Vanden Eynde <dieter.vandeneynde@netlash.com>
 */
class Navigation extends Base\Object
{
    /**
     * The navigation array, will be used to build the navigation
     *
     * @var    array
     */
    public $navigation = array();

    /**
     * URL-instance
     *
     * @var    Url
     */
    protected $URL;

    /**
     * @param KernelInterface $kernel
     */
    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        // store for later use throughout the application
        $this->getContainer()->set('navigation', $this);

        $this->URL = $this->getContainer()->get('url');

        // check if navigation cache file exists
        if (!is_file(BACKEND_CACHE_PATH . '/Navigation/navigation.php')) {
            $this->buildCache();
        }

        $navigation = array();

        // require navigation-file
        require BACKEND_CACHE_PATH . '/Navigation/navigation.php';

        // load it
        $this->navigation = (array) $navigation;
        $this->navigation = $this->addActiveStateToNavigation($this->navigation);

        // cleanup navigation (not needed for god user)
        if (!Authentication::getUser()->isGod()) {
            $this->navigation = $this->cleanup($this->navigation);
        }
    }

    /**
     * Build navigation cache file.
     */
    public function buildCache()
    {
        $navigationTree = '';

        // build tree starting with the root
        $this->buildNavigation(0, $navigationTree);

        // start generating PHP
        $value = '<?php' . "\n";
        $value .= '/*' . "\n";
        $value .= ' *' . "\n";
        $value .= ' * This file is generated by the Backend, it contains' . "\n";
        $value .= ' * more information about the navigation in the backend. Do NOT edit.' . "\n";
        $value .= ' * ' . "\n";
        $value .= ' * @author Backend' . "\n";
        $value .= ' * @generated	' . date('Y-m-d H:i:s') . "\n";
        $value .= ' */' . "\n";
        $value .= "\n";
        $value .= "\$navigation = array(\n";

        // add navigation tree
        $value .= $navigationTree;

        // close php
        $value .= ");\n";
        $value .= "\n";
        $value .= '?>';

        // store
        $fs = new Filesystem();
        $fs->dumpFile(
            BACKEND_CACHE_PATH . '/Navigation/navigation.php',
            $value
        );
    }

    public function parse(TwigTemplate $template)
    {
        $template->assign('navigation', $this->navigation);
    }

    private function addActiveStateToNavigation($navigation, $depth = 0)
    {
        $selectedKeys = $this->getSelectedKeys();

        foreach ($navigation as $key => &$item) {
            if ($key == $selectedKeys[$depth]) {
                $item['active'] = true;

                if (!empty($item['children'])) {
                    $item['children'] = $this->addActiveStateToNavigation($item['children'], $depth + 1);
                }
            }
        }

        return $navigation;
    }

    /**
     * Build navigation tree for a parent id.
     *
     * @param int    $parentId The id of the parent.
     * @param string $output   The output, will all output.
     * @param int    $depth    The current depth.
     */
    private function buildNavigation($parentId, &$output, $depth = 1)
    {
        // prefix every line with some tabs based on the depth
        $prefix = str_repeat("\t", $depth);

        // get navigation for backend
        $navigation = (array) BackendModel::getContainer()->get('database')->getRecords(
            'SELECT bn.*, COUNT(bn2.id) AS num_children
             FROM backend_navigation AS bn
             LEFT OUTER JOIN backend_navigation AS bn2 ON bn2.parent_id = bn.id
             WHERE bn.parent_id = ?
             GROUP BY bn.id
             ORDER BY bn.sequence ASC',
            array((int) $parentId)
        );

        // output
        foreach ($navigation as $i => $item) {
            // check if this item has children, later used
            $hasChildren = (bool) $item['num_children'];
            $hasSelectedFor = $item['selected_for'] !== null;

            // no url set, fetch the url of the first child
            if ($item['url'] == '') {
                $item['url'] = $this->getNavigationUrl($item['id']);
            }

            // general
            $output .= $prefix . "array(\n";
            $output .= $prefix . "\t'url' => '" . $item['url'] . "',\n";
            $output .= $prefix . "\t'label' => '" . $item['label'] . "'" .
                       (($hasChildren || $hasSelectedFor) ? ',' : '') . "\n";

            // selected for
            if ($hasSelectedFor) {
                // unserialize
                $selectedFor = unserialize($item['selected_for']);

                // make sure we have items
                if (!empty($selectedFor)) {
                    // open
                    $output .= $prefix . "\t'selected_for' => array(\n";

                    // add each item
                    foreach ($selectedFor as $ii => $selectedItem) {
                        $output .= $prefix . "\t\t'" . $selectedItem .
                                   "'" . ($ii < (count($selectedFor) - 1) ? ',' : '') . "\n";
                    }

                    // close
                    $output .= $prefix . "\t)" . ($hasChildren ? ',' : '') . "\n";
                }
            }

            // add children
            if ($hasChildren) {
                // open
                $output .= $prefix . "\t'children' => array(\n";

                // children
                $this->buildNavigation($item['id'], $output, $depth + 2);

                // close
                $output .= $prefix . "\t)\n";
            }

            // close
            $output .= $prefix . ')' . ($i < (count($navigation) - 1) ? ',' : '') . "\n";
        }
    }

    /**
     * Clean the navigation
     *
     * @param array $navigation The navigation array.
     * @return array
     */
    private function cleanup(array $navigation)
    {
        foreach ($navigation as $key => $value) {
            $allowedChildren = array();
            $allowed = true;

            if (!isset($value['url']) || !isset($value['label'])) {
                $allowed = false;
            }

            list($module, $action) = explode('/', $value['url']);
            $module = \SpoonFilter::toCamelCase($module);
            $action = \SpoonFilter::toCamelCase($action);

            if (!Authentication::isAllowedModule($module)) {
                $allowed = false;
            }

            if (!Authentication::isAllowedAction($action, $module)) {
                $allowed = false;
            }

            if (isset($value['children']) && is_array($value['children']) && !empty($value['children'])) {
                foreach ($value['children'] as $keyB => $valueB) {
                    $allowed = true;
                    $allowedChildrenB = array();

                    if (!isset($valueB['url']) || !isset($valueB['label'])) {
                        $allowed = false;
                    }

                    list($module, $action) = explode('/', $valueB['url']);
                    $module = \SpoonFilter::toCamelCase($module);
                    $action = \SpoonFilter::toCamelCase($action);

                    if (!Authentication::isAllowedModule($module)) {
                        $allowed = false;
                    }

                    if (!Authentication::isAllowedAction($action, $module)) {
                        $allowed = false;
                    }

                    // has children
                    if (isset($valueB['children']) && is_array($valueB['children']) && !empty($valueB['children'])) {
                        // loop children
                        foreach ($valueB['children'] as $keyC => $valueC) {
                            $allowed = true;

                            if (!isset($valueC['url']) || !isset($valueC['label'])) {
                                $allowed = false;
                            }

                            list($module, $action) = explode('/', $valueC['url']);
                            $module = \SpoonFilter::toCamelCase($module);
                            $action = \SpoonFilter::toCamelCase($action);

                            if (!Authentication::isAllowedModule($module)) {
                                $allowed = false;
                            }

                            if (!Authentication::isAllowedAction($action, $module)) {
                                $allowed = false;
                            }

                            if (!$allowed) {
                                unset($navigation[$key]['children'][$keyB]['children'][$keyC]);
                                continue;
                            } elseif (!in_array(
                                $navigation[$key]['children'][$keyB]['children'][$keyC],
                                $allowedChildrenB
                            )
                            ) {
                                // store allowed children
                                $allowedChildrenB[] = $navigation[$key]['children'][$keyB]['children'][$keyC];
                            }
                        }
                    }


                    if (!$allowed && empty($allowedChildrenB)) {
                        // error occurred and no allowed children on level B
                        unset($navigation[$key]['children'][$keyB]);
                        continue;
                    } elseif (!in_array(
                        $navigation[$key]['children'][$keyB],
                        $allowedChildren
                    )
                    ) {
                        // store allowed children on level B
                        $allowedChildren[] = $navigation[$key]['children'][$keyB];
                    }

                    // assign new base url for level B
                    if (!empty($allowedChildrenB)) {
                        $navigation[$key]['children'][$keyB]['url'] = $allowedChildrenB[0]['url'];
                    }
                }
            }

            // error occurred and no allowed children
            if (!$allowed && empty($allowedChildren)) {
                unset($navigation[$key]);
                continue;
            } elseif (!empty($allowedChildren)) {
                $allowed = true;
                list($module, $action) = explode('/', $allowedChildren[0]['url']);

                if (!Authentication::isAllowedModule($module)) {
                    $allowed = false;
                }

                if (!Authentication::isAllowedAction($action, $module)) {
                    $allowed = false;
                }

                if ($allowed) {
                    $navigation[$key]['url'] = $allowedChildren[0]['url'];
                } else {
                    $child = reset($navigation[$key]['children']);
                    $navigation[$key]['url'] = $child['url'];
                }
            }
        }

        return $navigation;
    }

    /**
     * Try to determine the selected state
     *
     * @param array $value The value.
     * @param int   $key   The key.
     * @param array $keys  The previous marked keys.
     * @return mixed
     */
    private function compareURL(array $value, $key, $keys = array())
    {
        // create active url
        $activeURL = $this->URL->getModule() . '/' . $this->URL->getAction();

        // we use the lowercased versions in the url
        $activeURL = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $activeURL));

        // add current key
        $keys[] = $key;

        // sub action?
        if (isset($value['selected_for']) && in_array($activeURL, (array) $value['selected_for'])) {
            return $keys;
        }

        // if the URL is available and same as the active one we have what we need.
        if (isset($value['url']) && $value['url'] == $activeURL) {
            if (isset($value['children'])) {
                // loop the children
                foreach ($value['children'] as $key => $value) {
                    // recursive here...
                    $subKeys = $this->compareURL($value, $key, $keys);

                    // wrap it up
                    if (!empty($subKeys)) {
                        return $subKeys;
                    }
                }
            }

            // fallback
            return $keys;
        }

        // any children
        if (isset($value['children'])) {
            // loop the children
            foreach ($value['children'] as $key => $value) {
                // recursive here...
                $subKeys = $this->compareURL($value, $key, $keys);

                // wrap it up
                if (!empty($subKeys)) {
                    return $subKeys;
                }
            }
        }
    }

    /**
     * Get the url of a navigation item.
     * If the item doesn't have an id, it will search recursively until it finds one.
     *
     * @param int $id The id to search for.
     * @return string
     */
    private function getNavigationUrl($id)
    {
        $id = (int) $id;

        // get url
        $item = (array) BackendModel::getContainer()->get('database')->getRecord(
            'SELECT id, url FROM backend_navigation WHERE id = ?',
            array($id)
        );

        if (empty($item)) {
            return '';
        } elseif ($item['url'] != '') {
            return $item['url'];
        } else {
            // get the first child
            $childId = (int) BackendModel::getContainer()->get('database')->getVar(
                'SELECT id FROM backend_navigation WHERE parent_id = ? ORDER BY sequence ASC LIMIT 1',
                array($id)
            );

            // get its url
            return $this->getNavigationUrl($childId);
        }
    }

    /**
     * Get the selected keys based on the current module/actions
     *
     * @return array
     */
    private function getSelectedKeys()
    {
        $keys = array();
        foreach ($this->navigation as $key => $value) {
            $keys = $this->compareURL($value, $key, array());

            // stop when we found something
            if (!empty($keys)) {
                break;
            }
        }

        return $keys;
    }
}
