<?php

namespace App\Tests\Command;

use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests @see FindAndUploadFilesCommand.
 *
 * @author Oscar Reimer <oscar.reimer@debricked.com>
 */
class FindAndUploadFilesCommandTest extends KernelTestCase
{
    /**
     * @var Command
     */
    private $command;

    /**
     * @var CommandTester
     */
    private $commandTester;

    public function setUp()
    {
        parent::setUp();

        $kernel = self::createKernel();
        $application = new Application($kernel);
        $this->command = $application->find(FindAndUploadFilesCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }

    private function zipFileContents($repositoryName, $commitName): array
    {
        // Helper function to return the contents of a zip file for a given upload. Assumes --keep-zip is set.
        $zipFilename = \str_replace('/', '-', "{$repositoryName}_{$commitName}.zip");
        $zip = new \ZipArchive();
        $result = $zip->open($zipFilename, \ZipArchive::CHECKCONS);
        $this->assertTrue($result === true, 'Failed to open zip file!');

        $filenames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filenames[] = $zip->statIndex($i)['name'];
        }
        return $filenames;
    }

    public function testExecuteInvalidCredentials()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => 'invalid@invalid.invalid',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => 'invalid',
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Invalid credentials.', $output);
    }

    public function testExecute()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }

    public function testExecuteWithoutBranch()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }

    public function testExecuteDisabledRecursiveAndDifferentBase()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            'base-directory' => '/vendor/',
            '--recursive-file-search' => 0,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Recursive search is disabled', $output);
        $this->assertContains('Nothing to upload!', $output);
    }

    public function testExecuteNothingExcluded()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            'base-directory' => '/tests/DependencyFiles/',
            '--recursive-file-search' => true,
            '--excluded-directories' => '',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('No directories will be ignored', $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('tests/DependencyFiles/composer.lock', $output);
        $this->assertContains('tests/DependencyFiles/package-lock.json', $output);
        $this->assertNotContains('Successfully created zip file', $output);
        $this->assertNotContains('dependency tree files', $output);
        $this->assertRegExp('/Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+/', $output);
        $this->assertRegExp('/Skipping\s+\/home\/tests\/DependencyFiles\/Gradle\/MPChartExample\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertRegExp('/Skipping\s+\/home\/tests\/DependencyFiles\/Gradle\/MPChartLib\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertRegExp('/Skipping\s+\/home\/tests\/DependencyFiles\/Gradle\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }

    public function testUploadAllFiles()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-all-files-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            '--recursive-file-search' => true,
            '--excluded-directories' => '',
            '--upload-all-files' => true,
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('Successfully created zip file', $output);
        $this->assertContains('Gradle/MPChartExample/build.gradle', $output);
        $this->assertContains('Gradle/MPChartLib/build.gradle ', $output);
        $this->assertContains('Gradle/build.gradle ', $output);
        $this->assertNotContains('dependency tree files', $output);
        $this->assertNotContains('Recursive search is disabled', $output);

        // Check that some files are in the zip, e.g., some source file and dependency file.
        $files = $this->zipFileContents('test-all-files-repository', 'test-commit');
        $err_message = 'These files were inside zip: ' . json_encode($files);
        $this->assertContains('README.md', $files, $err_message);
        $this->assertContains('tests/AdjacentFiles/Gradle/MPChartExample/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/MPChartLib/build.gradle', $files, $err_message);
        $this->assertContains('src/Command/FindAndUploadFilesCommand.php', $files, $err_message);

        // Check that zip filenames doesn't start with / or have multiple // inside them.
        foreach ($files as $file) {
            $this->assertNotRegexp('#^/#', $file);
            $this->assertNotRegexp('#//#', $file);
        }

        // Check that we have a lot of files in the zip.
        $this->assertTrue(count($files) > 500, 'Too few files in zip, found ' . count($files));
    }

    public function testUploadAllFilesBaseDirectory()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-all-files-repository-base',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            '--recursive-file-search' => true,
            '--excluded-directories' => 'vendor',
            '--upload-all-files' => true,
            'base-directory' => '/tests/DependencyFiles/Gradle/',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('Successfully created zip file', $output);
        $this->assertContains('Gradle/MPChartExample/build.gradle', $output);
        $this->assertContains('Gradle/MPChartLib/build.gradle ', $output);
        $this->assertContains('Gradle/build.gradle ', $output);
        $this->assertNotContains('dependency tree files', $output);
        $this->assertNotContains('Recursive search is disabled', $output);

        // Check that some files are in the zip, e.g., some source file and dependency file.
        $files = $this->zipFileContents('test-all-files-repository-base', 'test-commit');
        $err_message = 'These files were inside zip: ' . json_encode($files);
        $this->assertContains('tests/DependencyFiles/Gradle/MPChartExample/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/MPChartLib/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/gradle/wrapper/gradle-wrapper.properties', $files, $err_message);

        // Check that nothing except the base directory is in the zip.
        foreach ($files as $file) {
            $this->assertRegexp('#^tests/DependencyFiles/Gradle#', $file);
        }

        $this->assertNotContains('tests/AdjacentFiles/Gradle/build.gradle', $files);
        // Check that we have a reasonable amount of files in the zip.
        $this->assertTrue(count($files) >= 6, 'Too few files in zip, found ' . count($files));
        $this->assertTrue(count($files) <= 14, 'Too many files in zip, found ' . count($files));
    }

    public function testUploadsAdjacentDependencyTreeFilesAsZip()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-adjacent-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            'base-directory' => '/tests/AdjacentFiles/Gradle/',
            '--recursive-file-search' => true,
            '--excluded-directories' => '',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('No directories will be ignored', $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('Gradle/MPChartExample/build.gradle ', $output);
        $this->assertContains('Gradle/MPChartLib/build.gradle ', $output);
        $this->assertContains('Gradle/build.gradle ', $output);
        $this->assertContains('Successfully created zip file with 3 extra file(s)', $output);
        $this->assertContains('Successfully uploaded 3 dependency tree files', $output);
        $this->assertNotRegExp('/Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+/', $output);
        $this->assertNotRegExp('/Skipping\s+\/home\/tests\/DependencyFiles\/Gradle\/MPChartExample\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertNotRegExp('/Skipping\s+\/home\/tests\/DependencyFiles\/Gradle\/MPChartLib\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertNotRegExp('/Skipping\s+\/home\/tests\/DependencyFiles\/Gradle\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertNotContains('Recursive search is disabled', $output);

        $files = $this->zipFileContents('test-adjacent-repository', 'test-commit');
        $this->assertCount(3, $files, 'These files were in zip: ' . json_encode($files));
        $this->assertContains('tests/AdjacentFiles/Gradle/.debricked-gradle-dependencies.txt', $files);
        $this->assertContains('tests/AdjacentFiles/Gradle/MPChartExample/.debricked-gradle-dependencies.txt', $files);
        $this->assertContains('tests/AdjacentFiles/Gradle/MPChartLib/.debricked-gradle-dependencies.txt', $files);
    }

    public function testDontKeepZip()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'ziprepo',
            'commit-name' => 'dontkeep',
            'repository-url' => 'repository-url',
            'integration-name' => 'cli',
            'base-directory' => '/tests/AdjacentFiles/Gradle/',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertFalse(\file_exists('ziprepo_dontkeep.zip'));
    }

}
