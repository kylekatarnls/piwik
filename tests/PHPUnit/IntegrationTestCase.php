<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests;

use Exception;
use Piwik\API\DocumentationGenerator;
use Piwik\API\Proxy;
use Piwik\API\Request;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\ReportRenderer;
use Piwik\Translate;
use Piwik\UrlHelper;
use Piwik\Tests\Impl\TestRequestCollection;
use Piwik\Tests\Impl\TestRequestResponse;
use Piwik\Tests\Impl\ApiTestConfig;
use Piwik\Log;
use PHPUnit_Framework_TestCase;

require_once PIWIK_INCLUDE_PATH . '/libs/PiwikTracker/PiwikTracker.php';

/**
 * Base class for Integration tests.
 *
 * Provides helpers to track data and then call API get* methods to check outputs automatically.
 *
 */
abstract class IntegrationTestCase extends PHPUnit_Framework_TestCase
{
    /**
     * Identifies the last language used in an API/Controller call.
     *
     * @var string
     */
    protected $lastLanguage;

    protected $missingExpectedFiles = array();
    protected $comparisonFailures = array();

    public static function setUpBeforeClass()
    {
        Log::debug("Setting up " . get_called_class());

        if (!isset(static::$fixture)) {
            $fixture = new Fixture();
        } else {
            $fixture = static::$fixture;
        }

        $fixture->testCaseClass = get_called_class();

        try {
            $fixture->performSetUp();
        } catch (Exception $e) {
            static::fail("Failed to setup fixture: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    public static function tearDownAfterClass()
    {
        Log::debug("Tearing down " . get_called_class());

        if (!isset(static::$fixture)) {
            $fixture = new Fixture();
        } else {
            $fixture = static::$fixture;
        }

        $fixture->performTearDown();
    }

    /**
     * Returns true if continuous integration running this request
     * Useful to exclude tests which may fail only on this setup
     */
    public static function isTravisCI()
    {
        $travis = getenv('TRAVIS');
        return !empty($travis);
    }

    public static function isPhpVersion53()
    {
        return strpos(PHP_VERSION, '5.3') === 0;
    }

    public static function isMysqli()
    {
        return getenv('MYSQL_ADAPTER') == 'MYSQLI';
    }

    protected function alertWhenImagesExcludedFromTests()
    {
        if (!Fixture::canImagesBeIncludedInScheduledReports()) {
            $this->markTestSkipped(
                'Scheduled reports generated during integration tests will not contain the image graphs. ' .
                    'For tests to generate images, use a machine with the following specifications : ' .
                    'OS = '.Fixture::IMAGES_GENERATED_ONLY_FOR_OS.', PHP = '.Fixture::IMAGES_GENERATED_FOR_PHP .
                    ' and GD = ' . Fixture::IMAGES_GENERATED_FOR_GD
            );
        }
    }

    /**
     * Return 4 Api Urls for testing scheduled reports :
     * - one in HTML format with all available reports
     * - one in PDF format with all available reports
     * - two in SMS (one for each available report: MultiSites.getOne & MultiSites.getAll)
     *
     * @param string $dateTime eg '2010-01-01 12:34:56'
     * @param string $period eg 'day', 'week', 'month', 'year'
     * @return array
     */
    protected static function getApiForTestingScheduledReports($dateTime, $period)
    {
        $apiCalls = array();

        // HTML Scheduled Report
        array_push(
            $apiCalls,
            array(
                'ScheduledReports.generateReport',
                array(
                    'testSuffix'             => '_scheduled_report_in_html_tables_only',
                    'date'                   => $dateTime,
                    'periods'                => array($period),
                    'format'                 => 'original',
                    'fileExtension'          => 'html',
                    'otherRequestParameters' => array(
                        'idReport'     => 1,
                        'reportFormat' => ReportRenderer::HTML_FORMAT,
                        'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                    )
                )
            )
        );

        // CSV Scheduled Report
        array_push(
            $apiCalls,
            array(
                'ScheduledReports.generateReport',
                array(
                    'testSuffix'             => '_scheduled_report_in_csv',
                    'date'                   => $dateTime,
                    'periods'                => array($period),
                    'format'                 => 'original',
                    'fileExtension'          => 'csv',
                    'otherRequestParameters' => array(
                        'idReport'     => 1,
                        'reportFormat' => ReportRenderer::CSV_FORMAT,
                        'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                    )
                )
            )
        );

        if (Fixture::canImagesBeIncludedInScheduledReports()) {
            // PDF Scheduled Report
            // tests/PHPUnit/Integration/processed/test_ecommerceOrderWithItems_scheduled_report_in_pdf_tables_only__ScheduledReports.generateReport_week.original.pdf
            array_push(
                $apiCalls,
                array(
                     'ScheduledReports.generateReport',
                     array(
                         'testSuffix'             => '_scheduled_report_in_pdf_tables_only',
                         'date'                   => $dateTime,
                         'periods'                => array($period),
                         'format'                 => 'original',
                         'fileExtension'          => 'pdf',
                         'otherRequestParameters' => array(
                             'idReport'     => 1,
                             'reportFormat' => ReportRenderer::PDF_FORMAT,
                             'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                         )
                     )
                )
            );
        }

        // SMS Scheduled Report, one site
        array_push(
            $apiCalls,
            array(
                 'ScheduledReports.generateReport',
                 array(
                     'testSuffix'             => '_scheduled_report_via_sms_one_site',
                     'date'                   => $dateTime,
                     'periods'                => array($period),
                     'format'                 => 'original',
                     'fileExtension'          => 'sms.txt',
                     'otherRequestParameters' => array(
                         'idReport'   => 2,
                         'outputType' => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                     )
                 )
            )
        );

        // SMS Scheduled Report, all sites
        array_push(
            $apiCalls,
            array(
                 'ScheduledReports.generateReport',
                 array(
                     'testSuffix'             => '_scheduled_report_via_sms_all_sites',
                     'date'                   => $dateTime,
                     'periods'                => array($period),
                     'format'                 => 'original',
                     'fileExtension'          => 'sms.txt',
                     'otherRequestParameters' => array(
                         'idReport'   => 3,
                         'outputType' => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                     )
                 )
            )
        );

        if (Fixture::canImagesBeIncludedInScheduledReports()) {
            // HTML Scheduled Report with images
            array_push(
                $apiCalls,
                array(
                     'ScheduledReports.generateReport',
                     array(
                         'testSuffix'             => '_scheduled_report_in_html_tables_and_graph',
                         'date'                   => $dateTime,
                         'periods'                => array($period),
                         'format'                 => 'original',
                         'fileExtension'          => 'html',
                         'otherRequestParameters' => array(
                             'idReport'     => 4,
                             'reportFormat' => ReportRenderer::HTML_FORMAT,
                             'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                         )
                     )
                )
            );

            // mail report with one row evolution based png graph
            array_push(
                $apiCalls,
                array(
                     'ScheduledReports.generateReport',
                     array(
                         'testSuffix'             => '_scheduled_report_in_html_row_evolution_graph',
                         'date'                   => $dateTime,
                         'periods'                => array($period),
                         'format'                 => 'original',
                         'fileExtension'          => 'html',
                         'otherRequestParameters' => array(
                             'idReport'     => 5,
                             'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                         )
                     )
                )
            );
        }

        return $apiCalls;
    }

    protected function _testApiUrl($testName, $apiId, $requestUrl, $compareAgainst, $xmlFieldsToRemove = array(), $params = array())
    {
        list($processedFilePath, $expectedFilePath) =
            $this->getProcessedAndExpectedPaths($testName, $apiId, $format = null, $compareAgainst);

        $processedResponse = TestRequestResponse::loadFromApi($params, $requestUrl);
        if (empty($compareAgainst)) {
            $processedResponse->save($processedFilePath);
        }

        try {
            $expectedResponse = TestRequestResponse::loadFromFile($expectedFilePath, $params, $requestUrl);
        } catch (Exception $ex) {
            $this->handleMissingExpectedFile($expectedFilePath, $processedResponse);
            return;
        }

        try {
            TestRequestResponse::assertEquals($expectedResponse, $processedResponse, "Differences with expected in '$processedFilePath'");
        } catch (Exception $ex) {
            $this->comparisonFailures[] = $ex;
        }
    }

    private function handleMissingExpectedFile($expectedFilePath, TestRequestResponse $processedResponse)
    {
        $this->missingExpectedFiles[] = $expectedFilePath;

        print("The expected file is not found at '$expectedFilePath'. The Processed response was:");
        print("\n----------------------------\n\n");
        var_dump($processedResponse->getResponseText());
        print("\n----------------------------\n");
    }

    public static function assertApiResponseHasNoError($response)
    {
        if(!is_string($response)) {
            $response = json_encode($response);
        }
        self::assertTrue(stripos($response, 'error') === false, "error in $response");
        self::assertTrue(stripos($response, 'exception') === false, "exception in $response");
    }

    protected static function getProcessedAndExpectedDirs()
    {
        $path = static::getPathToTestDirectory();
        $processedPath = $path . '/processed/';

        if (!is_dir($processedPath)) {
            mkdir($processedPath, $mode = 0777, $recursive = true);
        }

        if (!is_writable($processedPath)) {
            self::fail('To run the tests, you need to give write permissions to the following directory (create it if '
                      . 'it doesn\'t exist).<code><br/>mkdir ' . $processedPath . '<br/>chmod 777 ' . $processedPath
                      . '</code><br/>');
        }

        return array($processedPath, $path . '/expected/');
    }

    private function getProcessedAndExpectedPaths($testName, $testId, $format = null, $compareAgainst = false)
    {
        $filenameSuffix = '__' . $testId;
        if ($format) {
            $filenameSuffix .= ".$format";
        }

        $processedFilename = $testName . $filenameSuffix;

        $expectedFilename = $compareAgainst ? ('test_' . $compareAgainst) : $testName;
        $expectedFilename .= $filenameSuffix;

        list($processedDir, $expectedDir) = static::getProcessedAndExpectedDirs();

        return array($processedDir . $processedFilename, $expectedDir . $expectedFilename);
    }

    /**
     * Returns an array describing the API methods to call & compare with
     * expected output.
     *
     * The returned array must be of the following format:
     * <code>
     * array(
     *     array('SomeAPI.method', array('testOption1' => 'value1', 'testOption2' => 'value2'),
     *     array(array('SomeAPI.method', 'SomeOtherAPI.method'), array(...)),
     *     .
     *     .
     *     .
     * )
     * </code>
     *
     * Valid test options are described in the ApiTestConfig class docs.
     *
     * All test options are optional, except 'idSite' & 'date'.
     */
    public function getApiForTesting()
    {
        return array();
    }

    /**
     * Gets the string prefix used in the name of the expected/processed output files.
     */
    public static function getOutputPrefix()
    {
        $parts = explode("\\", get_called_class());
        $result = end($parts);
        $result = str_replace('Test_Piwik_Integration_', '', $result);
        return $result;
    }

    /**
     * Assert that the response of an API method call is the same as the contents in an
     * expected file.
     *
     * @param string $api ie, `"UserSettings.getBrowser"`
     * @param array $queryParams Query parameters to send to the API.
     */
    public function assertApiResponseEqualsExpected($apiMethod, $queryParams)
    {
        $this->runApiTests($apiMethod, array(
            'idSite' => $queryParams['idSite'],
            'date' => $queryParams['date'],
            'periods' => $queryParams['period'],
            'testSuffix' => '_' . $this->getName(), // TODO: instead of using a test suffix, the whole file name should just be the test method
            'otherRequestParameters' => $queryParams
        ));
    }

    /**
     * Runs API tests.
     */
    protected function runApiTests($api, $params)
    {
        $testConfig = new ApiTestConfig($params);

        // make sure that the reports we process here are not directly deleted in ArchiveProcessor/PluginsArchiver
        // (because we process reports in the past, they would sometimes be invalid, and would have been deleted)
        \Piwik\ArchiveProcessor\Rules::disablePurgeOutdatedArchives();

        $testName = 'test_' . static::getOutputPrefix();
        $this->missingExpectedFiles = array();
        $this->comparisonFailures = array();

        if ($testConfig->disableArchiving) {
            Rules::$archivingDisabledByTests = true;
            Config::getInstance()->General['browser_archiving_disabled_enforce'] = 1;
        } else {
            Rules::$archivingDisabledByTests = false;
            Config::getInstance()->General['browser_archiving_disabled_enforce'] = 0;
        }

        if ($testConfig->language) {
            $this->changeLanguage($testConfig->language);
        }

        $testRequests = new TestRequestCollection($api, $testConfig, $api);

        foreach ($testRequests->getRequestUrls() as $apiId => $requestUrl) {
            $this->_testApiUrl($testName . $testConfig->testSuffix, $apiId, $requestUrl, $testConfig->compareAgainst, $testConfig->xmlFieldsToRemove, $params);
        }

        // Restore normal purge behavior
        \Piwik\ArchiveProcessor\Rules::enablePurgeOutdatedArchives();

        // change the language back to en
        if ($this->lastLanguage != 'en') {
            $this->changeLanguage('en');
        }

        if (!empty($this->missingExpectedFiles)) {
            $expectedDir = dirname(reset($this->missingExpectedFiles));
            $this->fail(" ERROR: Could not find expected API output '"
                . implode("', '", $this->missingExpectedFiles)
                . "'. For new tests, to pass the test, you can copy files from the processed/ directory into"
                . " $expectedDir  after checking that the output is valid. %s ");
        }

        // Display as one error all sub-failures
        if (!empty($this->comparisonFailures)) {
            $this->printComparisonFailures();
            throw reset($this->comparisonFailures);
        }

        return count($this->comparisonFailures) == 0;
    }

    private function printComparisonFailures()
    {
        $messages = '';
        foreach ($this->comparisonFailures as $index => $failure) {
            $msg = $failure->getMessage();
            $msg = strtok($msg, "\n");
            $messages .= "\n#" . ($index + 1) . ": " . $msg;
        }
        $messages .= " \n ";

        print($messages);
    }

    /**
     * changing the language within one request is a bit fancy
     * in order to keep the core clean, we need a little hack here
     *
     * @param string $langId
     */
    protected function changeLanguage($langId)
    {
        if ($this->lastLanguage != $langId) {
            $_GET['language'] = $langId;
            Translate::reset();
            Translate::reloadLanguage($langId);
        }

        $this->lastLanguage = $langId;
    }

    /**
     * Path where expected/processed output files are stored.
     */
    public static function getPathToTestDirectory()
    {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Integration';
    }

    /**
     * Returns an array associating table names w/ lists of row data.
     *
     * @return array
     */
    protected static function getDbTablesWithData()
    {
        $result = array();
        foreach (DbHelper::getTablesInstalled() as $tableName) {
            $result[$tableName] = Db::fetchAll("SELECT * FROM `$tableName`");
        }
        return $result;
    }

    /**
     * Truncates all tables then inserts the data in $tables into each
     * mapped table.
     *
     * @param array $tables Array mapping table names with arrays of row data.
     */
    protected static function restoreDbTables($tables)
    {
        // truncate existing tables
        DbHelper::truncateAllTables();

        // insert data
        $existingTables = DbHelper::getTablesInstalled();
        foreach ($tables as $table => $rows) {
            // create table if it's an archive table
            if (strpos($table, 'archive_') !== false && !in_array($table, $existingTables)) {
                $tableType = strpos($table, 'archive_numeric') !== false ? 'archive_numeric' : 'archive_blob';

                $createSql = DbHelper::getTableCreateSql($tableType);
                $createSql = str_replace(Common::prefixTable($tableType), $table, $createSql);
                Db::query($createSql);
            }

            if (empty($rows)) {
                continue;
            }

            $rowsSql = array();
            $bind = array();
            foreach ($rows as $row) {
                $values = array();
                foreach ($row as $value) {
                    if (is_null($value)) {
                        $values[] = 'NULL';
                    } else if (is_numeric($value)) {
                        $values[] = $value;
                    } else if (!ctype_print($value)) {
                        $values[] = "x'" . bin2hex(substr($value, 1)) . "'";
                    } else {
                        $values[] = "?";
                        $bind[] = $value;
                    }
                }

                $rowsSql[] = "(" . implode(',', $values) . ")";
            }

            $sql = "INSERT INTO `$table` VALUES " . implode(',', $rowsSql);
            Db::query($sql, $bind);
        }
    }

    /**
     * Drops all archive tables.
     */
    public static function deleteArchiveTables()
    {
        foreach (ArchiveTableCreator::getTablesArchivesInstalled() as $table) {
            Log::debug("Dropping table $table");

            Db::query("DROP TABLE IF EXISTS `$table`");
        }

        ArchiveTableCreator::refreshTableList($forceReload = true);
    }
}
