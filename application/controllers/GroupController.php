<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

use \Exception;
use \Zend_Controller_Action_Exception;
use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Authentication\UserGroup\UserGroupBackend;
use Icinga\Authentication\UserGroup\UserGroupBackendInterface;
use Icinga\Data\Reducible;
use Icinga\Data\Filter\Filter;
use Icinga\Forms\Config\UserGroupForm;
use Icinga\Web\Controller;
use Icinga\Web\Form;
use Icinga\Web\Notification;
use Icinga\Web\Url;
use Icinga\Web\Widget;

class GroupController extends Controller
{
    /**
     * Redirect to this controller's list action
     */
    public function indexAction()
    {
        $this->redirectNow('group/list');
    }

    /**
     * List all user groups of a single backend
     */
    public function listAction()
    {
        $backendNames = array_map(
            function ($b) { return $b->getName(); },
            $this->loadUserGroupBackends('Icinga\Data\Selectable')
        );
        $this->view->backendSelection = new Form();
        $this->view->backendSelection->setAttrib('class', 'backend-selection');
        $this->view->backendSelection->setUidDisabled();
        $this->view->backendSelection->setMethod('GET');
        $this->view->backendSelection->setTokenDisabled();
        $this->view->backendSelection->addElement(
            'select',
            'backend',
            array(
                'autosubmit'    => true,
                'label'         => $this->translate('Usergroup Backend'),
                'multiOptions'  => array_combine($backendNames, $backendNames),
                'value'         => $this->params->get('backend')
            )
        );

        $backend = $this->getUserGroupBackend($this->params->get('backend'));
        if ($backend === null) {
            $this->view->backend = null;
            return;
        }

        $query = $backend->select(array('group_name'));
        $filterEditor = Widget::create('filterEditor')
            ->setQuery($query)
            ->preserveParams('limit', 'sort', 'dir', 'view', 'backend')
            ->ignoreParams('page')
            ->handleRequest($this->getRequest());
        $query->applyFilter($filterEditor->getFilter());
        $this->setupFilterControl($filterEditor);

        try {
            $this->setupPaginationControl($query);
            $this->view->groups = $query;
        } catch (Exception $e) {
            Notification::error($e->getMessage());
            Logger::error($e);
        }

        $this->view->backend = $backend;
        $this->createListTabs()->activate('group/list');

        $this->setupLimitControl();
        $this->setupSortControl(
            array(
                'group_name'    => $this->translate('Group'),
                'parent_name'   => $this->translate('Parent'),
                'created_at'    => $this->translate('Created at'),
                'last_modified' => $this->translate('Last modified')
            ),
            $query
        );
    }

    /**
     * Show a group
     */
    public function showAction()
    {
        $groupName = $this->params->getRequired('group');
        $backend = $this->getUserGroupBackend($this->params->getRequired('backend'));

        $group = $backend->select(array(
            'group_name',
            'parent_name',
            'created_at',
            'last_modified'
        ))->where('group_name', $groupName)->fetchRow();
        if ($group === false) {
            $this->httpNotFound(sprintf($this->translate('Group "%s" not found'), $groupName));
        }

        $members = $backend
            ->select()
            ->from('group_membership', array('user_name'))
            ->where('group_name', $groupName);

        $filterEditor = Widget::create('filterEditor')
            ->setQuery($members)
            ->preserveParams('limit', 'sort', 'dir', 'view', 'backend', 'group')
            ->ignoreParams('page')
            ->handleRequest($this->getRequest());
        $members->applyFilter($filterEditor->getFilter());

        $this->setupFilterControl($filterEditor);
        $this->setupPaginationControl($members);
        $this->setupLimitControl();
        $this->setupSortControl(
            array(
                'user_name'     => $this->translate('Username'),
                'created_at'    => $this->translate('Created at'),
                'last_modified' => $this->translate('Last modified')
            ),
            $members
        );

        $this->view->group = $group;
        $this->view->backend = $backend;
        $this->view->members = $members;
        $this->createShowTabs($backend->getName(), $groupName)->activate('group/show');

        if ($backend instanceof Reducible) {
            $removeForm = new Form();
            $removeForm->setUidDisabled();
            $removeForm->setAction(
                Url::fromPath('group/removemember', array('backend' => $backend->getName(), 'group' => $groupName))
            );
            $removeForm->addElement('hidden', 'user_name', array(
                'isArray'       => true,
                'decorators'    => array('ViewHelper')
            ));
            $removeForm->addElement('hidden', 'redirect', array(
                'value'         => Url::fromPath('group/show', array(
                    'backend'   => $backend->getName(),
                    'group'     => $groupName
                )),
                'decorators'    => array('ViewHelper')
            ));
            $removeForm->addElement('button', 'btn_submit', array(
                'escape'        => false,
                'type'          => 'submit',
                'class'         => 'link-like',
                'value'         => 'btn_submit',
                'decorators'    => array('ViewHelper'),
                'label'         => $this->view->icon('trash'),
                'title'         => $this->translate('Remove this member')
            ));
            $this->view->removeForm = $removeForm;
        }
    }

    /**
     * Add a group
     */
    public function addAction()
    {
        $backend = $this->getUserGroupBackend($this->params->getRequired('backend'), 'Icinga\Data\Extensible');
        $form = new UserGroupForm();
        $form->setRedirectUrl(Url::fromPath('group/list', array('backend' => $backend->getName())));
        $form->setRepository($backend);
        $form->add()->handleRequest();

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Edit a group
     */
    public function editAction()
    {
        $groupName = $this->params->getRequired('group');
        $backend = $this->getUserGroupBackend($this->params->getRequired('backend'), 'Icinga\Data\Updatable');

        $row = $backend->select(array('group_name'))->where('group_name', $groupName)->fetchRow();
        if ($row === false) {
            $this->httpNotFound(sprintf($this->translate('Group "%s" not found'), $groupName));
        }

        $form = new UserGroupForm();
        $form->setRedirectUrl(
            Url::fromPath('group/show', array('backend' => $backend->getName(), 'group' => $groupName))
        );
        $form->setRepository($backend);
        $form->edit($groupName, get_object_vars($row))->handleRequest();

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Remove a group
     */
    public function removeAction()
    {
        $groupName = $this->params->getRequired('group');
        $backend = $this->getUserGroupBackend($this->params->getRequired('backend'), 'Icinga\Data\Reducible');

        if ($backend->select()->where('group_name', $groupName)->count() === 0) {
            $this->httpNotFound(sprintf($this->translate('Group "%s" not found'), $groupName));
        }

        $form = new UserGroupForm();
        $form->setRedirectUrl(Url::fromPath('group/list', array('backend' => $backend->getName())));
        $form->setRepository($backend);
        $form->remove($groupName)->handleRequest();

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Remove a group member
     */
    public function removememberAction()
    {
        $this->assertHttpMethod('POST');
        $groupName = $this->params->getRequired('group');
        $backend = $this->getUserGroupBackend($this->params->getRequired('backend'), 'Icinga\Data\Reducible');

        if ($backend->select()->where('group_name', $groupName)->count() === 0) {
            $this->httpNotFound(sprintf($this->translate('Group "%s" not found'), $groupName));
        }

        $form = new Form(array(
            'onSuccess' => function ($form) use ($groupName, $backend) {
                foreach ($form->getValue('user_name') as $userName) {
                    try {
                        $backend->delete(
                            'group_membership',
                            Filter::matchAll(
                                Filter::where('group_name', $groupName),
                                Filter::where('user_name', $userName)
                            )
                        );
                        Notification::success(sprintf(
                            t('User "%s" has been removed from group "%s"'),
                            $userName,
                            $groupName
                        ));
                    } catch (Exception $e) {
                        Notification::error($e->getMessage());
                    }
                }

                $redirect = $form->getValue('redirect');
                if (! empty($redirect)) {
                    $form->setRedirectUrl(htmlspecialchars_decode($redirect));
                }

                return true;
            }
        ));
        $form->setUidDisabled();
        $form->setSubmitLabel('btn_submit'); // Required to ensure that isSubmitted() is called
        $form->addElement('hidden', 'user_name', array('required' => true, 'isArray' => true));
        $form->addElement('hidden', 'redirect');
        $form->handleRequest();
    }

    /**
     * Return all user group backends implementing the given interface
     *
     * @param   string  $interface      The class path of the interface, or null if no interface check should be made
     *
     * @return  array
     */
    protected function loadUserGroupBackends($interface = null)
    {
        $backends = array();
        foreach (Config::app('groups') as $backendName => $backendConfig) {
            $candidate = UserGroupBackend::create($backendName, $backendConfig);
            if (! $interface || $candidate instanceof $interface) {
                $backends[] = $candidate;
            }
        }

        return $backends;
    }

    /**
     * Return the given user group backend or the first match in order
     *
     * @param   string  $name           The name of the backend, or null in case the first match should be returned
     * @param   string  $interface      The interface the backend should implement, no interface check if null
     *
     * @return  UserGroupBackendInterface
     *
     * @throws  Zend_Controller_Action_Exception    In case the given backend name is invalid
     */
    protected function getUserGroupBackend($name = null, $interface = 'Icinga\Data\Selectable')
    {
        if ($name !== null) {
            $config = Config::app('groups');
            if (! $config->hasSection($name)) {
                $this->httpNotFound(sprintf($this->translate('User group backend "%s" not found'), $name));
            } else {
                $backend = UserGroupBackend::create($name, $config->getSection($name));
                if ($interface && !$backend instanceof $interface) {
                    $interfaceParts = explode('\\', strtolower($interface));
                    throw new Zend_Controller_Action_Exception(
                        sprintf(
                            $this->translate('User group backend "%s" is not %s'),
                            $name,
                            array_pop($interfaceParts)
                        ),
                        400
                    );
                }
            }
        } else {
            $backends = $this->loadUserGroupBackends($interface);
            $backend = array_shift($backends);
        }

        return $backend;
    }

    /**
     * Create the tabs to list users and groups
     */
    protected function createListTabs()
    {
        $tabs = $this->getTabs();
        $tabs->add(
            'user/list',
            array(
                'title'     => $this->translate('List users of authentication backends'),
                'label'     => $this->translate('Users'),
                'icon'      => 'user',
                'url'       => 'user/list'
            )
        );
        $tabs->add(
            'group/list',
            array(
                'title'     => $this->translate('List groups of user group backends'),
                'label'     => $this->translate('Groups'),
                'icon'      => 'users',
                'url'       => 'group/list'
            )
        );

        return $tabs;
    }

    /**
     * Create the tabs to display when showing a group
     *
     * @param   string  $backendName
     * @param   string  $groupName
     */
    protected function createShowTabs($backendName, $groupName)
    {
        $tabs = $this->getTabs();
        $tabs->add(
            'group/show',
            array(
                'title'     => sprintf($this->translate('Show group %s'), $groupName),
                'label'     => $this->translate('Group'),
                'icon'      => 'users',
                'url'       => Url::fromPath('group/show', array('backend' => $backendName, 'group' => $groupName))
            )
        );

        return $tabs;
    }
}