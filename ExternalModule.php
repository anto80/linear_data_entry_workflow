<?php
/**
 * @file
 * Provides ExternalModule class for Linear Data Entry Workflow.
 */

namespace LinearDataEntryWorkflow\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Records;
use REDCap;

/**
 * ExternalModule class for Linear Data Entry Workflow.
 */
class ExternalModule extends AbstractExternalModule {

    /**
     * @inheritdoc
     */
    function hook_every_page_top($project_id) {
        if (!$project_id) {
            return;
        }

        // Initializing settings JS variable.
        echo '<script>var linearDataEntryWorkflow = {};</script>';
        $record = null;

        switch (PAGE) {
            case 'DataEntry/record_home.php':
                if (empty($_GET['id'])) {
                    break;
                }

                $record = $_GET['id'];

            case 'DataEntry/record_status_dashboard.php':
                $location = str_replace('.php', '', str_replace('DataEntry/', '', PAGE));
                $arm = empty($_GET['arm']) ? 1 : $_GET['arm'];

                $this->loadRFIO($location, $arm, $record);
                break;
        }
    }

    /**
     * @inheritdoc
     */
    function hook_data_entry_form($project_id, $record = null, $instrument, $event_id, $group_id = null) {
        global $Proj;

        if (!$record) {
            $record = $_GET['id'];
        }

        $this->loadRFIO('data_entry_form', $Proj->eventInfo[$event_id]['arm_num'], $record, $event_id, $instrument);
        
        // if ($this->loadRFIO('data_entry_form', $Proj->eventInfo[$event_id]['arm_num'], $record, $event_id, $instrument)) {
        //     $this->loadFDEC($instrument);
        //      $this->loadAutoLock($instrument);
        // }
    }

    /**
     * Loads RFIO (review fields in order) feature.
     *
     * @param string $location
     *   The location to apply RFIO. Can be:
     *   - data_entry_form
     *   - record_home
     *   - record_status_dashboard
     * @param string $arm
     *   The arm name.
     * @param int $record
     *   The data entry record ID.
     * @param int $event_id
     *   The event ID. Only required when $location = "data_entry_form".
     * @param string $instrument
     *   The form/instrument name.
     *
     * @return bool
     *   TRUE if the current user has access to the current form;
     *   FALSE if the user is going to be redirected out the page.
     */
    protected function loadRFIO($location, $arm, $record = null, $event_id = null, $instrument = null) {
        // Proj is a REDCap var used to pass information about the current project.
        global $Proj;

        $records = $record ? array($record) : Records::getRecordList($Proj->project_id);
        $forms_status = Records::getFormStatus($Proj->project_id, $records, $arm);

        if ($independent_events_allowed = $this->getProjectSetting('allow-independent-events')) {
            foreach ($forms_status as $id => $data) {
                foreach (array_keys($data) as $event) {
                    // Appending fake "completed" forms to the beggining of list
                    // to make sure at least the first form will be displayed.
                    $forms_status[$id][$event] = array('___ldew_aux_form' => array(2)) + $forms_status[$id][$event];
                }
            }
        }
        else {
            reset($Proj->eventsForms);
            $first_event = key($Proj->eventsForms);

            foreach (array_keys($forms_status) as $id) {
                // Appending fake "completed" forms to the beggining of list
                // to make sure at least the first form will be displayed.
                $forms_status[$id][$first_event] = array('___ldew_aux_form' => array(2)) + $forms_status[$id][$first_event];
            }
        }

        $events_forms_exceptions = $this->getProjectSetting('events-forms-exceptions', $Proj->project_id);
        $events = $this->getProjectSetting('event-name', $Proj->project_id);
        $forms = $this->getProjectSetting('form-name', $Proj->project_id);
        $triggers_form = $this->getProjectSetting('trigger-after-form', $Proj->project_id);
        $triggers_event = $this->getProjectSetting('trigger-after-event', $Proj->project_id);
        $triggers = array();

        foreach ($triggers_form as $i => $t) {
            $event = $triggers_event[$i];
            $form = $triggers_form[$i];
            $triggers[$event][$form] = true;
        }

        $exceptions = array();
        foreach ($events_forms_exceptions as $i => $k) {
            $exceptions[] = $events[$i] . '|' . $forms[$i] . '|' . $triggers_event[$i] . $triggers_form[$i];
        }

        // Handling possible conflicts with CTSIT's Form Render Skip Logic.
        $frsl = array();
        if (defined('FORM_RENDER_SKIP_LOGIC_PREFIX')) {
            $frsl = ExternalModules::getModuleInstance(FORM_RENDER_SKIP_LOGIC_PREFIX)->getFormsAccessMatrix($event_id, $record);
        }

        $prev_event = '';
        $prev_form = '';

        $exceptionInstruments = array();

        // Getting denied forms.
        $denied_forms = array();
        $completed = array();
        foreach ($forms_status as $id => $data) {
            
            $denied_forms[$id] = array();
            $completed[$id] = array();

            // Getting completed forms
            if (count($triggers)) {

                foreach ($data as $event => $event_forms) {

                    $completed[$id][$event] = array();

                    foreach ($event_forms as $form => $form_status) {

                        // check if complete
                        $complete = false;
                        if (!empty($form_status)) {
                            $complete = true;

                            foreach ($form_status as $instance_status) {
                                if ($instance_status != 2) {
                                    $complete = false;
                                    break;
                                }
                            }
                        }

                        $completed[$id][$event][$form] = $complete;

                    }
                }
            }

            // var_Dump($completed);

            foreach (array_reverse($data, true) as $event => $event_forms) {
                
                $denied_forms[$id][$event] = array();

                foreach (array_reverse($event_forms, true) as $form => $form_status) {

                    // Event
                    if (in_array($event . '||', $exceptions)) {
                        $exceptionInstruments[$id][$event][$form] = true;
                        continue;
                    }

                    // Instrument
                    if (in_array('|' . $form . '|', $exceptions)) {
                        $exceptionInstruments[$id][$event][$form] = true;
                        continue;
                    }

                    // Event + Instrument
                    if (in_array($event . '|' . $form . '|', $exceptions)) {
                        $exceptionInstruments[$id][$event][$form] = true;
                        continue;
                    }

                    // Event + Instrument + trigger (After complete)
                    $skip = false;
                    foreach ($triggers as $trigger_event => $trigger_forms) {
                        foreach ($trigger_forms as $trigger_form => $tv) {
                            if (array_key_exists($trigger_event, $completed[$id]) && $completed[$id][$trigger_event][$trigger_form]) { // complete

                                if (
                                    in_array($event . '|' . $form . '|' . $trigger_event . $trigger_form, $exceptions) || 
                                    in_array('|' . $form . '|' . $trigger_event . $trigger_form, $exceptions)
                                ) {
                                    $skip = true;
                                }
                            }
                        }
                    }

                    if ($skip) {
                        $exceptionInstruments[$id][$event][$form] = true;
                        continue;
                    }

                    if (isset($frsl[$id][$event][$form]) && !$frsl[$id][$event][$form]) {
                        // Skip FRSL hidden forms.
                        continue;
                    }

                    if (!empty($form_status)) {
                        $complete = true;

                        foreach ($form_status as $instance_status) {
                            if ($instance_status != 2) {
                                $complete = false;
                                break;
                            }
                        }

                        if ($complete) {
                            // Since this form is complete, let's rollback the
                            // access block to the next form.
                            unset($denied_forms[$id][$prev_event][$prev_form]);
                        }

                        $prev_event = $event;
                        $prev_form = $form;
                        continue;
                    }

                    $denied_forms[$id][$event][$form] = $form;

                    $prev_event = $event;
                    $prev_form = $form;
                }

                if ($independent_events_allowed) {
                    $prev_event = '';
                    $prev_form = '';
                }
                /* BUG : if all instruments are in exceptions skip all */ 
                // elseif (!isset($denied_forms[$id][$prev_event][$prev_form])) {
                //     break;
                // }
            }
        }



        if ($record && $event_id && isset($denied_forms[$record][$event_id][$instrument])) {
            // Access denied to the current page.
            $this->redirect(APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . $Proj->project_id . '&id=' . $record . '&arm=' . $arm);
            return false;
        }


        $settings = array(
            'deniedForms' => $denied_forms,
            'location' => $location,
            'instrument' => $instrument,
            'exceptionInstruments' => $exceptionInstruments,
            'isException' => in_array($instrument, $exceptions),
            'forceButtonsDisplay' => $Proj->lastFormName == $instrument ? 'show' : false,
            'hideNextRecordButton' => $this->getProjectSetting('hide-next-record-button', $Proj->project_id),
        );

        if (!$settings['forceButtonsDisplay']) {
            $i = array_search($instrument, $Proj->eventsForms[$event_id]);
            $next_form = $Proj->eventsForms[$event_id][$i + 1];

            if (in_array($next_form, $exceptions)) {
                // Handling the case where the next form is an exception,
                // so we need to show the buttons no matter the form status.
                $settings['forceButtonsDisplay'] = 'show';
            }
            elseif ($settings['isException']) {
                // Handling 2 cases for exception forms:
                // - Case A: the next form is not accessible, so we need to keep
                //   the buttons hidden, no matter if form gets shifted to
                //   Complete status.
                // - Case B: the next form is accessible, so we need to keep the
                //   buttons visible, no matter if form gets shifted to a non
                //   Completed status.
                $settings['forceButtonsDisplay'] = $forms_access[$record][$event_id][$next_form] ? 'show' : 'hide';
            }
        }

        $this->setJsSetting('rfio', $settings);
        $this->includeJs('js/rfio.js');

        return true;
    }

    /**
     * Loads FDEC (force data entry constraints) feature.
     *
     * @param string $instrument
     *   The instrument/form ID.
     * (optional) @param array $statuses_bypass
     *   An array of form statuses to bypass FDEC. Possible statuses:
     *   - 0 (Incomplete)
     *   - 1 (Unverified)
     *   - 2 (Completed)
     *   - "" (Empty status)
     */
    protected function loadFDEC($instrument, $statuses_bypass = array('', 0, 1)) {
        $exceptions = $this->getProjectSetting('forms-exceptions', $project_id);
        if ($exceptions && in_array($instrument, $exceptions)) {
            return;
        }

        global $Proj;

        // Markup of required fields bullets list.
        $bullets = '';

        // Selectors to search for empty required fields.
        $req_fields_selectors = array();

        // Getting required fields from form config.
        foreach (array_keys($Proj->forms[$instrument]['fields']) as $field_name) {
            $field_info = $Proj->metadata[$field_name];
            if (!$field_info['field_req']) {
                continue;
            }

            // The bullets are hidden for default, since we do not know which ones will be empty.
            $field_label = filter_tags(label_decode($field_info['element_label']));
            $bullets .= '<div class="req-bullet req-bullet--' . $field_name . '" style="margin-left: 1.5em; text-indent: -1em; display: none;"> &bull; ' . $field_label . '</div>';

            $req_fields_selectors[] = '#questiontable ' . ($field_info['element_type'] == 'select' ? 'select' : 'input') . '[name="' . $field_name . '"]:visible';
        }

        // Printing required fields popup (hidden yet).
        echo '
            <div id="preemptiveReqPopup" title="Some fields are required!" style="display:none;text-align:left;">
                <p>You did not provide a value for some fields that require a value. Please enter a value for the fields on this page that are listed below.</p>
                <div style="font-size:11px; font-family: tahoma, arial; font-weight: bold; padding: 3px 0;">' . $bullets . '</div>
            </div>';

        $settings = array(
            'statusesBypass' => array_map(function($value) { return (string) $value; }, $statuses_bypass),
            'requiredFieldsSelector' => implode(',', $req_fields_selectors),
            'instrument' => $instrument,
        );

        $this->setJsSetting('fdec', $settings);
        $this->includeJs('js/fdec.js');
    }

    /**
     * Loads auto-lock feature.
     */
    protected function loadAutoLock($instrument) {
      global $user_rights;
      global $Proj;

      //get list of exceptions
      if (!$exceptions = $this->getProjectSetting('forms-exceptions', $Proj->project_id)) {
          $exceptions = array();
      }

      //if current form is in the exception list then disable auto-locking
      if (in_array($instrument, $exceptions)) {
        return;
      }

      //get list of roles to enforce auto-locking on
      $roles_to_lock = $this->getProjectSetting("auto-locked-roles", $Proj->project_id);

      //load auto-lock script if user is in an auto-locked role
      if (in_array($user_rights["role_id"], $roles_to_lock)) {
        $this->includeJs("js/auto-lock.js");
      }
    }

    /**
     * Redirects user to the given URL.
     *
     * This function basically replicates redirect() function, but since EM
     * throws an error when an exit() is called, we need to adapt it to the
     * EM way of exiting.
     */
    protected function redirect($url) {
        if (headers_sent()) {
            // If contents already output, use javascript to redirect instead.
            echo '<script>window.location.href="' . $url . '";</script>';
        }
        else {
            // Redirect using PHP.
            header('Location: ' . $url);
        }

        $this->exitAfterHook();
    }

    /**
     * Includes a local JS file.
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function includeJs($path) {
        echo '<script src="' . $this->getUrl($path) . '"></script>';
    }


    /** 
     * 
     * Riattivare per testare il JS in locale, altrimenti carica lo script remoto 
     * 
     * */
    // function getUrl($path, $noAuth = false, $useApiEndpoint = false)
    // {
    //     $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    //     // Include 'md' files as well to render README.md documentation.
    //     $isPhpPath = in_array($extension, ['php', 'md']) || (preg_match("/\.php\?/", $path));
    //     if ($isPhpPath || $useApiEndpoint) {
    //         // GET parameters after php file -OR- php extension
    //         $url = ExternalModules::getUrl($this->PREFIX, $path, $useApiEndpoint);
    //         if ($isPhpPath) {
    //             $pid = self::detectProjectId();
    //             if (!empty($pid) && !preg_match("/[\&\?]pid=/", $url)){
    //                 $url .= '&pid='.$pid;
    //             }
    //             if ($noAuth && !preg_match("/NOAUTH/", $url)) {
    //                 $url .= '&NOAUTH';
    //             }
    //         }
    //     } else {
    //         // This must be a resource, like an image or css/js file.
    //         // Go ahead and return the version specific url.
    //         $pathPrefix = ExternalModules::getModuleDirectoryPath($this->PREFIX, $this->VERSION);
    //         $url = '/modules/' . $this->PREFIX . '_' . $this->VERSION . '/' . $path . '?' . filemtime($pathPrefix . '/' . $path);
    //         //ExternalModules::getModuleDirectoryUrl($this->PREFIX, $this->VERSION) . $path . '?' . filemtime($pathPrefix . '/' . $path);
    //     }
    //     return $url;
    // }
    

    /**
     * Sets a JS setting.
     *
     * @param string $key
     *   The setting key to be appended to the module settings object.
     * @param mixed $value
     *   The setting value.
     */
    protected function setJsSetting($key, $value) {
        echo '<script>linearDataEntryWorkflow.' . $key . ' = ' . json_encode($value) . ';</script>';
    }
}
