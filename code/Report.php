<?php

namespace SilverStripe\Reports;

use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\SS_List;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Core\Convert;
use SilverStripe\View\ViewableData;
use ReflectionClass;
use SilverStripe\ORM\CMSPreviewable ;

/**
 * Base "abstract" class creating reports on your data.
 *
 * Creating reports
 * ================
 *
 * Creating a new report is a matter overloading a few key methods
 *
 *  {@link title()}: Return the title - i18n is your responsibility
 *  {@link description()}: Return the description - i18n is your responsibility
 *  {@link sourceQuery()}: Return a SS_List of the search results
 *  {@link columns()}: Return information about the columns in this report.
 *  {@link parameterFields()}: Return a FieldList of the fields that can be used to filter this
 *  report.
 *
 * If you wish to modify the report in more extreme ways, you could overload these methods instead.
 *
 * {@link getReportField()}: Return a FormField in the place where your report's TableListField
 * usually appears.
 * {@link getCMSFields()}: Return the FieldList representing the complete right-hand area of the
 * report, including the title, description, parameter fields, and results.
 *
 * Showing reports to the user
 * ===========================
 *
 * Right now, all subclasses of SS_Report will be shown in the ReportAdmin. In SS3 there is only
 * one place where reports can go, so this class is greatly simplifed from its version in SS2.
 */
class Report extends ViewableData
{
    /**
     * This is the title of the report,
     * used by the ReportAdmin templates.
     *
     * @var string
     */
    protected $title = '';

    /**
     * This is a description about what this
     * report does. Used by the ReportAdmin
     * templates.
     *
     * @var string
     */
    protected $description = '';

    /**
     * The class of object being managed by this report.
     * Set by overriding in your subclass.
     */
    protected $dataClass = 'SilverStripe\\CMS\\Model\\SiteTree';

    /**
     * A field that specifies the sort order of this report
     * @var int
     */
    protected $sort = 0;

    /**
     * Reports which should not be collected and returned in get_reports
     * @var array
     */
    public static $excluded_reports = array(
        'SilverStripe\\Reports\\Report',
        'SilverStripe\\Reports\\ReportWrapper',
        'SilverStripe\\Reports\\SideReportWrapper',
    );

    /**
     * Return the title of this report.
     *
     * You have two ways of specifying the description:
     *  - overriding description(), which lets you support i18n
     *  - defining the $description property
     */
    public function title()
    {
        return $this->title;
    }

    /**
     * Allows access to title as a property
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title();
    }

    /**
     * Return the description of this report.
     *
     * You have two ways of specifying the description:
     *  - overriding description(), which lets you support i18n
     *  - defining the $description property
     */
    public function description()
    {
        return $this->description;
    }

    /**
     * Return the {@link SQLQuery} that provides your report data.
     */
    public function sourceQuery($params)
    {
        if ($this->hasMethod('sourceRecords')) {
            return $this->sourceRecords($params, null, null)->dataQuery();
        } else {
            user_error("Please override sourceQuery()/sourceRecords() and columns() or, if necessary, override getReportField()", E_USER_ERROR);
        }
    }

    /**
     * Return a SS_List records for this report.
     */
    public function records($params)
    {
        if ($this->hasMethod('sourceRecords')) {
            return $this->sourceRecords($params, null, null);
        } else {
            $query = $this->sourceQuery();
            $results = new ArrayList();
            foreach ($query->execute() as $data) {
                $class = $this->dataClass();
                $result = new $class($data);
                $results->push($result);
            }
            return $results;
        }
    }

    /**
     * Return the data class for this report
     */
    public function dataClass()
    {
        return $this->dataClass;
    }


    public function getLink($action = null)
    {
        return Controller::join_links(
            ReportAdmin::singleton()->Link('show'),
            $this->sanitiseClassName(static::class),
            $action
        );
    }

	/**
	 * Sanitise a model class' name for inclusion in a link
	 *
	 * @param string $class
	 * @return string
	 */
	protected function sanitiseClassName($class) {
		return str_replace('\\', '-', $class);
	}


    /**
     * counts the number of objects returned
     * @param array $params - any parameters for the sourceRecords
     * @return int
     */
    public function getCount($params = array())
    {
        $sourceRecords = $this->sourceRecords($params, null, null);
        if (!$sourceRecords instanceof SS_List) {

            user_error(static::class . "::sourceRecords does not return an SS_List", E_USER_NOTICE);
            return "-1";
        }
        return $sourceRecords->count();
    }

    /**
     * Exclude certain reports classes from the list of Reports in the CMS
     * @param $reportClass Can be either a string with the report classname or an array of reports classnames
     */
    public static function add_excluded_reports($reportClass)
    {
        if (is_array($reportClass)) {
            self::$excluded_reports = array_merge(self::$excluded_reports, $reportClass);
        } else {
            if (is_string($reportClass)) {
                //add to the excluded reports, so this report doesn't get used
                self::$excluded_reports[] = $reportClass;
            }
        }
    }

    /**
     * Return an array of excluded reports. That is, reports that will not be included in
     * the list of reports in report admin in the CMS.
     * @return array
     */
    public static function get_excluded_reports()
    {
        return self::$excluded_reports;
    }

    /**
     * Return the SS_Report objects making up the given list.
     * @return Array of SS_Report objects
     */
    public static function get_reports()
    {
        $reports = ClassInfo::subclassesFor(get_called_class());

        $reportsArray = array();
        if ($reports && count($reports) > 0) {
            //collect reports into array with an attribute for 'sort'
            foreach ($reports as $report) {
                if (in_array($report, self::$excluded_reports)) {
                    continue;
                }   //don't use the SS_Report superclass
                $reflectionClass = new ReflectionClass($report);
                if ($reflectionClass->isAbstract()) {
                    continue;
                }   //don't use abstract classes

                $reportObj = new $report;
                if (method_exists($reportObj, 'sort')) {
                    $reportObj->sort = $reportObj->sort();
                }  //use the sort method to specify the sort field
                $reportsArray[$report] = $reportObj;
            }
        }

        uasort($reportsArray, function ($a, $b) {
            if ($a->sort == $b->sort) {
                return 0;
            } else {
                return ($a->sort < $b->sort) ? -1 : 1;
            }
        });

        return $reportsArray;
    }

    /////////////////////// UI METHODS ///////////////////////


    /**
     * Returns a FieldList with which to create the CMS editing form.
     * You can use the extend() method of FieldList to create customised forms for your other
     * data objects.
     *
     * @uses getReportField() to render a table, or similar field for the report. This
     * method should be defined on the SS_Report subclasses.
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = new FieldList();

        if ($description = $this->description()) {
            $fields->push(new LiteralField('ReportDescription', "<p>" . $description . "</p>"));
        }

        // Add search fields is available
        if ($this->hasMethod('parameterFields') && $parameterFields = $this->parameterFields()) {
            foreach ($parameterFields as $field) {
                // Namespace fields for easier handling in form submissions
                $field->setName(sprintf('filters[%s]', $field->getName()));
                $field->addExtraClass('no-change-track'); // ignore in changetracker
                $fields->push($field);
            }

            // Add a search button
            $formAction = new FormAction('updatereport', _t('SilverStripe\\Forms\\GridField\\GridField.Filter', 'Filter'));
            $formAction->addExtraClass("m-b-2");

            $fields->push($formAction);
        }

        $fields->push($this->getReportField());

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    public function getCMSActions()
    {
        // getCMSActions() can be extended with updateCMSActions() on a extension
        $actions = new FieldList();
        $this->extend('updateCMSActions', $actions);
        return $actions;
    }

    /**
     * Return a field, such as a {@link GridField} that is
     * used to show and manipulate data relating to this report.
     *
     * Generally, you should override {@link columns()} and {@link records()} to make your report,
     * but if they aren't sufficiently flexible, then you can override this method.
     *
     * @return \SilverStripe\Forms\FormField subclass
     */
    public function getReportField()
    {
        // TODO Remove coupling with global state
        $params = isset($_REQUEST['filters']) ? $_REQUEST['filters'] : array();
        $items = $this->sourceRecords($params, null, null);

        $gridFieldConfig = GridFieldConfig::create()->addComponents(

            new GridFieldButtonRow('before'),
            new GridFieldPrintButton('buttons-before-left'),
            new GridFieldExportButton('buttons-before-left'),
            new GridFieldToolbarHeader(),
            new GridFieldSortableHeader(),
            new GridFieldDataColumns(),
            new GridFieldPaginator()
        );
        $gridField = new GridField('Report', null, $items, $gridFieldConfig);
        $columns = $gridField->getConfig()->getComponentByType('SilverStripe\\Forms\\GridField\\GridFieldDataColumns');
        $displayFields = array();
        $fieldCasting = array();
        $fieldFormatting = array();

        // Parse the column information
        foreach ($this->columns() as $source => $info) {
            if (is_string($info)) {
                $info = array('title' => $info);
            }

            if (isset($info['formatting'])) {
                $fieldFormatting[$source] = $info['formatting'];
            }
            if (isset($info['csvFormatting'])) {
                $csvFieldFormatting[$source] = $info['csvFormatting'];
            }
            if (isset($info['casting'])) {
                $fieldCasting[$source] = $info['casting'];
            }

            if (isset($info['link']) && $info['link']) {
                $fieldFormatting[$source] = function($value, $item) {
                    if ($item instanceof CMSPreviewable) {
                        /** @var CMSPreviewable $item */
                        return sprintf(
                            '<a class="grid-field__link-block" href="%s" title="%s">%s</a>',
                            Convert::raw2att($item->CMSEditLink()),
                            Convert::raw2att($value),
                            Convert::raw2xml($value)
                        );
                    }
                    return $value;
				};
            }

            $displayFields[$source] = isset($info['title']) ? $info['title'] : $source;
        }
        $columns->setDisplayFields($displayFields);
        $columns->setFieldCasting($fieldCasting);
        $columns->setFieldFormatting($fieldFormatting);

        return $gridField;
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        if (!$member && $member !== false) {
            $member = Member::currentUser();
        }

        $extended = $this->extendedCan('canView', $member);
        if ($extended !== null) {
            return $extended;
        }

        if ($member && Permission::checkMember($member, array('CMS_ACCESS_LeftAndMain', 'CMS_ACCESS_ReportAdmin'))) {
            return true;
        }

        return false;
    }

    /**
     * Helper to assist with permission extension
     *
     * {@see DataObject::extendedCan()}
     *
     * @param string $methodName Method on the same object, e.g. {@link canEdit()}
     * @param Member|int $member
     * @return boolean|null
     */
    public function extendedCan($methodName, $member)
    {
        $results = $this->extend($methodName, $member);
        if ($results && is_array($results)) {
            // Remove NULLs
            $results = array_filter($results, function ($v) {return !is_null($v);});
            // If there are any non-NULL responses, then return the lowest one of them.
            // If any explicitly deny the permission, then we don't get access
            if ($results) {
                return min($results);
            }
        }
        return null;
    }


    /**
     * Return the name of this report, which
     * is used by the templates to render the
     * name of the report in the report tree,
     * the left hand pane inside ReportAdmin.
     *
     * @return string
     */
    public function TreeTitle()
    {
        return $this->title();
    }
}
