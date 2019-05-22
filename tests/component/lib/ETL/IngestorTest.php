<?php
/**
 * @package OpenXdmod\ComponentTests
 * @author Steven M. Gallo <smgallo@buffalo.edu>
 */

namespace ComponentTests\ETL;

use CCR\DB;

/**
 * Test various components of the ETLv2 ingestors. All tests in this file run the etl_overseer.php
 * script to test the entire pipeline. The following tests are performed:
 *
 * 1. Load invalid data and ensure that LOAD DATA INFILE returns appropriate warning messages.
 * 2. Insert truncated or out of range data and ensure that the SQL statements returns warning messages.
 * 3. Insert truncated and out of range data but hide SQL warnings.
 * 4. Insert truncated and out of range data but hide SQL warnings for incorrect values, leaving
 *    warnings for out of range values.
 * 5. Test the structured file ingestor using the directory scanner with one empty (0 byte)
 *    file, one file containing an empty JSON array, and another file containing 2 records of
 *    data.
 */

class IngestorTest extends \PHPUnit_Framework_TestCase
{
    const TEST_INPUT_DIR = '/tests/artifacts/xdmod/etlv2/configuration/input';
    const ACTION = 0;   // Run an overseer action
    const PIPELINE = 1; // Run an overseer pipeline

    /**
     * 1. Load invalid data and ensure that LOAD DATA INFILE returns appropriate warning messages.
     */

    public function testLoadDataInfileWarnings() {
        $result = $this->executeOverseer('xdmod.ingestor-tests.test-load-data-infile-warnings');

        $this->assertEquals(0, $result['exit_status'], "Exit code");

        /* We are expecting output such as:
         *
         * 2018-03-07 12:29:03 [warning] LOAD DATA warnings on table 'load_data_infile_test' generated by action test-load-data-infile-warnings
         * 2018-03-07 12:29:03 [warning] Warning   1265    Data truncated for column 'my_enum' at row 1
         * 2018-03-07 12:29:03 [warning] Warning   1265    Data truncated for column 'my_varchar8' at row 1
         * 2018-03-07 12:29:03 [warning] Warning   1265    Data truncated for column 'my_double' at row 1
         */

        $numWarnings = 0;

        if ( ! empty($result['stdout']) ) {
            foreach ( explode(PHP_EOL, trim($result['stdout'])) as $line ) {
                $this->assertRegExp('/\[warning\]/', $line);
                $numWarnings++;
            }
        }

        $this->assertGreaterThanOrEqual(4, $numWarnings, 'Expected number of SQL warnings');
        $this->assertEquals('', $result['stderr'], "Std Error");
    }

    /**
     * 2. Insert truncated or out of range data and ensure that the SQL statements returns warning
     *    messages.
     */

    public function testSqlWarnings() {
        $result = $this->executeOverseer('xdmod.ingestor-tests.test-sql-warnings');

        $this->assertEquals(0, $result['exit_status'], "Exit code");

        /* We are expecting output such as:
         *
         * 2018-03-08 13:49:08 [warning] SQL warnings on table '`modw_cloud`.`warning_test`' generated by action sql-warning-test
         * 2018-03-08 13:49:08 [warning] Warning 1264 Out of range value for column 'resource_id' at row 1
         * 2018-03-08 13:49:08 [warning] Warning 1366 Incorrect integer value: '' for column 'account_id' at row 1
         * 2018-03-08 13:49:08 [warning] Warning 1366 Incorrect integer value: '' for column 'provider_account_id' at row 1
         */

        $numWarnings = 0;

        if ( ! empty($result['stdout']) ) {
            foreach ( explode(PHP_EOL, trim($result['stdout'])) as $line ) {
                if ( false !== strpos($line, '[warning]') ) {
                    $numWarnings++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $numWarnings, 'Expected number of SQL warnings');
        $this->assertEquals('', $result['stderr'], "Std Error");
    }

    /**
     * 3. Insert truncated and out of range data but hide SQL warnings.
     */

    public function testHideSqlWarnings() {
        $result = $this->executeOverseer('xdmod.ingestor-tests.test-sql-warnings', '-o "hide_sql_warnings=true"');

        $this->assertEquals(0, $result['exit_status'], "Exit code");

        // We are expecting no warnings to be returned

        if ( ! empty($result['stdout']) ) {
            foreach ( explode(PHP_EOL, trim($result['stdout'])) as $line ) {
                $this->assertNotRegExp('/\[warning\]/', $line);
            }
        }

        $this->assertEquals('', $result['stderr'], "Std Error");
    }

    /**
     * 4. Insert truncated and out of range data but hide SQL warnings for incorrect values, leaving
     *    warnings for out of range values.
     */

    public function testHideSqlWarningCodes() {
        $result = $this->executeOverseer('xdmod.ingestor-tests.test-sql-warnings', '-o "hide_sql_warning_codes=1366"');

        $this->assertEquals(0, $result['exit_status'], "Exit code");

        /* We are expecting output such as:
         *
         * 2018-03-08 13:49:08 [warning] SQL warnings on table '`modw_cloud`.`warning_test`' generated by action sql-warning-test
         * 2018-03-08 13:49:08 [warning] Warning 1264 Out of range value for column 'resource_id' at row 1
         */

        $numWarnings = 0;

        if ( ! empty($result['stdout']) ) {
            foreach ( explode(PHP_EOL, trim($result['stdout'])) as $line ) {
                if ( false !== strpos($line, '[warning]') ) {
                    $numWarnings++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(2, $numWarnings, 'Expected number of SQL warnings');
        $this->assertEquals('', $result['stderr'], "Std Error");

        // Run the same action, but filter all expected warning codes.
        $result = $this->executeOverseer('xdmod.ingestor-tests.test-sql-warnings', '-o "hide_sql_warning_codes=[1264,1366]"');

        $this->assertEquals(0, $result['exit_status'], "Exit code");
        $numWarnings = 0;

        if ( ! empty($result['stdout']) ) {
            foreach ( explode(PHP_EOL, trim($result['stdout'])) as $line ) {
                if ( false !== strpos($line, '[warning]') ) {
                    $numWarnings++;
                }
            }
        }

        $this->assertEquals(0, $numWarnings, 'Expected number of SQL warnings');
        $this->assertEquals('', $result['stderr'], "Std Error");
    }

    /**
     * 5. Test the structured file ingestor using the directory scanner with one empty (0 byte)
     *    file, one file containing an empty JSON array, and another file containing 2 records of
     *    dat.
     */

    public function testStructuredFileIngestorWithDirectoryScanner() {
        $result = $this->executeOverseer(
            'xdmod.cloud-jobs.GenericRawCloudEventIngestor',
            sprintf('-v notice -d "CLOUD_EVENT_LOG_DIR=%s/generic_cloud_logs"', BASE_DIR . self::TEST_INPUT_DIR)
        );

        $this->assertEquals(0, $result['exit_status'], 'Exit code');
        $this->assertEquals('', $result['stderr'], 'Std Error');
    }

    /**
     * 6. Test the structured file ingestor using the same file in multiple actions to
     *    ensure that the file is fully processed each time.
     */

    public function testStructuredFileIngestorWithSameFile() {
        $result = $this->executeOverseer(
            'xdmod.structured-file',
            '-v notice',
            self::PIPELINE
        );

        // Parse the output looking for [notice] lines indicating how many records were loaded for
        // each action in the pipeline. Ensure the expected number of records were loaded.

        $recordsLoaded = array();

        foreach ( explode(PHP_EOL, trim($result['stdout'])) as $line ) {
            if ( false !== strpos($line, '[notice]') ) {
                $matches = array();
                if ( preg_match('/xdmod.structured-file.read-people-([0-9])/', $line, $matches) ) {
                    $number = $matches[1];
                    if ( preg_match('/records_loaded:\s*([0-9]+)/', $line, $matches) ) {
                        $recordsLoaded[$number] = $matches[1];
                    }
                }
            }
        }

        $this->assertEquals(1, $recordsLoaded[1], 'Records loaded');
        $this->assertEquals(3, $recordsLoaded[2], 'Records loaded');
        $this->assertEquals(3, $recordsLoaded[3], 'Records loaded');
        $this->assertEquals(0, $result['exit_status'], 'Exit code');
        $this->assertEquals('', $result['stderr'], 'Std Error');
    }

    /**
     * Execute a single ETL overseer action via the CLI script.
     *
     * @param string $action The name of the action to execute.
     * @param string $localOptions A string of additional options to pass to the overseer.
     * @param int $type Either an action or a pipeline
     */

    private function executeOverseer($name, $localOptions = "", $type = self::ACTION)
    {
        // Note that tests are run in the directory where the PHP class is defined.
        $overseer = realpath(BASE_DIR . '/tools/etl/etl_overseer.php');
        $configFile = realpath(BASE_DIR . self::TEST_INPUT_DIR . '/xdmod_etl_config_8.0.0.json');
        $options = sprintf(
            '-c %s %s %s %s',
            $configFile,
            (self::ACTION == $type ? '-a' : '-p'),
            $name,
            $localOptions
        );

        // Add a verbosity flag if the local options do not already contain one
        if ( "" == $localOptions || false === strpos($localOptions, '-v') ) {
            $options = sprintf('%s -v warning', $options);
        }

        $command = sprintf('%s %s', $overseer, $options);

        return $this->executeCommand($command);
    }

    /**
     * Execute a command.
     *
     * @param string $command The command to execute
     */

    private function executeCommand($command)
    {
        $pipes = array();

        $process = proc_open(
            $command,
            array(
                0 => array('file', '/dev/null', 'r'),  // STDIN
                1 => array('pipe', 'w'),               // STDOUT
                2 => array('pipe', 'w'),               // STDERR
            ),
            $pipes
        );

        if ( ! is_resource($process) ) {
            throw new Exception(sprintf('Failed to create %s subprocess', $command));
        }

        $stdout = stream_get_contents($pipes[1]);

        if ( false === $stdout ) {
            throw new Execption('Failed to get subprocess STDOUT');
        }

        $stderr = stream_get_contents($pipes[2]);

        if (false === $stderr) {
            throw new Execption('Failed to get subprocess STDERR');
        }

        $exitStatus = proc_close($process);

        return array(
            'exit_status' => $exitStatus,
            'stdout' => $stdout,
            'stderr' => $stderr,
        );
    }

    /**
     * Clean up tables created during the tests
     *
     * @return Nothing
     */

    public static function tearDownAfterClass()
    {
        $dbh = DB::factory('database');
        $dbh->execute('DROP TABLE IF EXISTS `test`.`load_data_infile_test`');
        $dbh->execute('DROP TABLE IF EXISTS `test`.`organizations`');
        $dbh->execute('DROP TABLE IF EXISTS `test`.`people`');
    }
}
