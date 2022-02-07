<?php

namespace App\Tests\Command;

use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests @see FindAndUploadFilesCommand.
 *
 * @author Oscar Reimer <oscar.reimer@debricked.com>
 */
class FindAndUploadFilesCommandTest extends KernelTestCase
{
    private Command $command;
    private CommandTester $commandTester;

    private function zipFileContents($repositoryName, $commitName): array
    {
        // Helper function to return the contents of a zip file for a given upload. Assumes --keep-zip is set.
        $zipFilename = \str_replace('/', '-', "{$repositoryName}_$commitName.zip");
        $zip = new \ZipArchive();
        $result = $zip->open($zipFilename, \ZipArchive::CHECKCONS);
        $this->assertTrue($result === true, 'Failed to open zip file!');

        $filenames = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $filenames[] = $zip->statIndex($i)['name'];
        }

        return $filenames;
    }

    public function testExecuteInvalidCredentials()
    {
        $this->setUpReal();
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
        $this->assertStringContainsString('Invalid credentials.', $output);
    }

    public function testExecute()
    {
        $this->setUpReal();
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            '--author' => 'test-author',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);
    }

    public function testExecuteWithoutBranch()
    {
        $this->setUpReal();
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
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);
    }

    public function testExecuteWithoutAuthor()
    {
        $this->setUpReal();
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
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);
    }

    public function testExecuteDisabledRecursiveAndDifferentBase()
    {
        $this->setUpReal();
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
        $this->assertStringContainsString('Recursive search is disabled', $output);
        $this->assertStringContainsString('Nothing to upload!', $output);
    }

    public function testExecuteNothingExcluded()
    {
        $this->setUpReal();
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
        $this->assertStringContainsString('No directories will be ignored', $output);
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringContainsString('composer.lock', $output);
        $this->assertStringContainsString('package-lock.json', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);
    }

    public function testMissingLockFile(): void
    {
        $this->setUpMocks();

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test--repository',
            'commit-name' => 'test--commit',
            'repository-url' => 'repository/url',
            'integration-name' => 'GitLab',
            'base-directory' => '/tests/DependencyFiles/Gradle/MPChartLib/',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('build.gradle', $output);
        $this->assertStringContainsString('Missing related dependency file(s)!', $output);
        $this->assertStringContainsString('[WARNING] This will result in slow scans and less precise results!', $output);
        $this->assertStringContainsString('Make sure to generate at least one of the following prior to scanning:', $output);
        $this->assertStringContainsString('* .debricked-gradle-dependencies.txt', $output);
        $this->assertStringContainsString('For more info: https://debricked.com/docs/language-support', $output);
        $this->assertStringEndsWith("to track the vulnerability scan\n", $output);
    }

    public function testUploadAllFiles()
    {
        $this->setUpReal();
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
            '--excluded-directories' => 'vendor',
            '--upload-all-files' => true,
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringContainsString('Gradle/MPChartExample/build.gradle', $output);
        $this->assertStringContainsString('Gradle/MPChartLib/build.gradle', $output);
        $this->assertStringContainsString('Gradle/build.gradle', $output);
        $this->assertStringContainsString('CsProj/exampleFile.csproj', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);

        // Check that some files are in the zip, e.g., some source file and dependency file.
        $files = $this->zipFileContents('test-all-files-repository', 'test-commit');
        $err_message = 'These files were inside zip: '.json_encode($files);
        $this->assertContains('README.md', $files, $err_message);
        $this->assertContains('tests/AdjacentFiles/Gradle/MPChartExample/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/MPChartLib/build.gradle', $files, $err_message);
        $this->assertContains('src/Command/FindAndUploadFilesCommand.php', $files, $err_message);

        // Check that zip filenames don't start with / or have multiple // inside them.
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('#^/#', $file);
            $this->assertDoesNotMatchRegularExpression('#//#', $file);
        }

        // Check that we have a lot of files in the zip.
        $this->assertTrue(count($files) > 200, 'Too few files in zip, found '.count($files));
    }

    public function testUploadAllFilesBaseDirectory()
    {
        $this->setUpReal();
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
        $this->assertStringContainsString("Files\n=====\n", $output);
        $this->assertStringContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringContainsString('MPChartExample/build.gradle', $output);
        $this->assertStringContainsString('MPChartLib/build.gradle', $output);
        $this->assertStringContainsString('build.gradle', $output);
        $this->assertStringNotContainsString('dependency tree files', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);

        // Check that some files are in the zip, e.g., some source file and dependency file.
        $files = $this->zipFileContents('test-all-files-repository-base', 'test-commit');
        $err_message = 'These files were inside zip: '.json_encode($files);
        $this->assertContains('tests/DependencyFiles/Gradle/MPChartExample/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/MPChartLib/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/build.gradle', $files, $err_message);
        $this->assertContains('tests/DependencyFiles/Gradle/gradle/wrapper/gradle-wrapper.properties', $files, $err_message);

        // Check that nothing except the base directory is in the zip.
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('#^tests/DependencyFiles/Gradle#', $file);
        }

        $this->assertNotContains('tests/AdjacentFiles/Gradle/build.gradle', $files);
        // Check that we have a reasonable amount of files in the zip.
        $this->assertGreaterThanOrEqual(6, count($files), 'Too few files in zip, found '.count($files));
        $this->assertLessThanOrEqual(14, count($files), 'Too many files in zip, found '.count($files));
    }

    public function testDontKeepZip()
    {
        $this->setUpReal();
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

    public function testSnippetAnalysis()
    {
        $this->setUpReal();
        // TestFiles doesn't have any dependency file, so the only thing uploaded is the fingerprints.
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-wfp',
            'commit-name' => 'does-upload',
            'repository-url' => 'repository-url',
            'integration-name' => 'cli',
            'base-directory' => '/tests/Analysis/TestFiles/',
            '--snippet-analysis' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringContainsString('Snippet analysis complete', $output);
    }

    public function testSnippetAnalysisWithUploadAllFiles()
    {
        $this->setUpReal();
        // TestFiles doesn't have any dependency file, so the only thing uploaded is the fingerprints.
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-wfp',
            'commit-name' => 'does-upload-all',
            'repository-url' => 'repository-url',
            'integration-name' => 'cli',
            'base-directory' => '/tests/Analysis/TestFiles/',
            '--upload-all-files' => true,
            '--keep-zip' => null,
            '--snippet-analysis' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Successfully uploaded zip file containing source code', $output);

        $files = $this->zipFileContents('test-wfp', 'does-upload-all');
        $this->assertCount(2, $files, 'These files were in zip: '.json_encode($files));
        $this->assertContains('tests/Analysis/TestFiles/TestSourceCombinedOutput.php', $files);
        $this->assertContains('tests/Analysis/TestFiles/TestSourceKernel.php', $files);
    }

    public function testSnippetAnalysisDisabled()
    {
        $this->setUpReal();
        // TestFiles doesn't have any dependency file, so the only thing uploaded would have been the fingerprints.
        // But that is disabled, so we look for Nothing to upload.
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-wfp',
            'commit-name' => 'doesntupload',
            'repository-url' => 'repository-url',
            'integration-name' => 'cli',
            'base-directory' => '/tests/Analysis/TestFiles/',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Nothing to upload!', $output);
    }

    public function testUploadAllFilesFalseMeansFalse()
    {
        $this->setUpMocks();

        $repositoryName = 'test-upload-all-files-false-means-false';
        $commitName = 'test-commit';
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => $repositoryName,
            'commit-name' => $commitName,
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--upload-all-files' => 'false',
            '--excluded-directories' => 'vendor,var',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $zipFilename = \str_replace('/', '-', "{$repositoryName}_$commitName.zip");
        $this->assertFalse(\is_file($zipFilename), 'Source code was zipped then it should not');
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
    }

    public function testUploadAllFilesTrueMeansTrue()
    {
        $this->setUpMocks();

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-upload-all-files-true-means-true',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--upload-all-files' => 'true',
            '--excluded-directories' => 'vendor,var',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $files = $this->zipFileContents('test-upload-all-files-true-means-true', 'test-commit');
        $this->verifyZipContentsUploadAll(true, $output, $files);
    }

    public function testUploadAllFiles0MeansFalse()
    {
        $this->setUpMocks();

        $repositoryName = 'test-upload-all-files-0-means-false';
        $commitName = 'test-commit';
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => $repositoryName,
            'commit-name' => $commitName,
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--upload-all-files' => '0',
            '--excluded-directories' => 'vendor,var',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $zipFilename = \str_replace('/', '-', "{$repositoryName}_$commitName.zip");
        $this->assertFalse(\is_file($zipFilename), 'Source code was zipped then it should not');
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
    }

    public function testUploadAllFiles1MeansTrue()
    {
        $this->setUpMocks();

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-upload-all-files-1-means-true',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--upload-all-files' => '1',
            '--excluded-directories' => 'vendor,var',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $files = $this->zipFileContents('test-upload-all-files-1-means-true', 'test-commit');
        $this->verifyZipContentsUploadAll(true, $output, $files);
    }

    public function testUploadAllFilesWeirdMeansError()
    {
        $this->setUpMocks();

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-upload-all-files-1-means-true',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository/url',
            'integration-name' => 'azureDevOps',
            '--upload-all-files' => 'weird',
            '--excluded-directories' => 'vendor,var',
            '--keep-zip' => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
    }

    public function testUploadUsingAccessToken()
    {
        $this->setUpMocks(true);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => '',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => 'secret_access_token',
            'repository-name' => 'test-upload-with-access-token',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'gitlab',
            '--excluded-directories' => 'vendor,var',
            '--branch-name' => 'test-branch',
            '--default-branch' => 'main',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);
    }

    public function testUploadUsingAccessTokenReal()
    {
        $this->setUpReal();

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => '',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_TOKEN'],
            'repository-name' => 'test-upload-with-access-token-real',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'gitlab',
            '--default-branch' => 'main',
            '--excluded-directories' => 'vendor,var',
            '--branch-name' => 'test-branch',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringNotContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);
    }

    /** Helper function for tests that check our upload all files flag.
     * @param bool     $uploadAll If we expect all files to be uploaded
     * @param string[] $files
     */
    private function verifyZipContentsUploadAll(bool $uploadAll, string $output, array $files): void
    {
        $this->assertStringContainsString('Successfully uploaded zip file containing source code', $output);
        $this->assertStringContainsString('Gradle/MPChartExample/build.gradle', $output);
        $this->assertStringContainsString('Gradle/MPChartLib/build.gradle', $output);
        $this->assertStringContainsString('Gradle/build.gradle', $output);
        $this->assertStringContainsString('CsProj/exampleFile.csproj', $output);
        $this->assertStringNotContainsString('Recursive search is disabled', $output);

        $err_message = 'These files were inside zip: '.json_encode($files);
        if ($uploadAll) {
            $this->assertContains('README.md', $files, $err_message);
            $this->assertContains('tests/AdjacentFiles/Gradle/MPChartExample/build.gradle', $files, $err_message);
            $this->assertContains('tests/DependencyFiles/Gradle/MPChartLib/build.gradle', $files, $err_message);
            $this->assertContains('src/Command/FindAndUploadFilesCommand.php', $files, $err_message);

            // Check that we have a lot of files in the zip.
            $this->assertTrue(count($files) > 50, 'Too few files in zip, found '.count($files));
        } else {
            // Check that zip does only have adjacent files, not all files.
            $this->assertCount(3, $files, 'These files were in zip: '.json_encode($files));
            $this->assertNotContains('README.md', $files, $err_message);
            $this->assertNotContains('src/Command/FindAndUploadFilesCommand.php', $files, $err_message);
            $this->assertContains('tests/AdjacentFiles/Gradle/.debricked-gradle-dependencies.txt', $files);
            $this->assertContains('tests/AdjacentFiles/Gradle/MPChartExample/.debricked-gradle-dependencies.txt', $files);
            $this->assertContains('tests/AdjacentFiles/Gradle/MPChartLib/.debricked-gradle-dependencies.txt', $files);
        }

        // Check that zip filenames don't start with / or have multiple // inside them.
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('#^/#', $file);
            $this->assertDoesNotMatchRegularExpression('#//#', $file);
        }
    }

    private function setUpReal(): void
    {
        $kernel = self::createKernel();
        $application = new Application($kernel);
        $this->command = $application->find(FindAndUploadFilesCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }

    private function setUpMocks(bool $expectAccessToken = false): void
    {
        $ciUploadId = null;
        $hasAuthed = false;
        $responseMockGenerator = function ($method, $url, $options) use (&$ciUploadId, &$hasAuthed, $expectAccessToken) {
            if (!$expectAccessToken && \strpos($url, '/api/login_check') !== false) {
                $hasAuthed = true;

                return new MockResponse(\json_encode([
                    'token' => 'eyImAToken',
                ]));
            } elseif ($expectAccessToken && \strpos($url, '/api/login_refresh') !== false) {
                $hasAuthed = true;
                $this->assertArrayHasKey('body', $options);
                $body = \json_decode($options['body'], true);
                $this->assertEquals('secret_access_token', $body['refresh_token']);

                return new MockResponse(\json_encode([
                    'token' => 'eyImATokenFromAccessToken',
                ]));
            } elseif (!$hasAuthed) {
                return new MockResponse('', ['http_code' => 401]);
            } elseif (\strpos($url, '/api/1.0/open/uploads/dependencies/files') !== false) {
                if ($ciUploadId === null) {
                    $ciUploadId = \rand(100, 1_000_000);
                }

                return new MockResponse(\json_encode([
                    'ciUploadId' => $ciUploadId,
                    'uploadProgramsFileId' => \rand(100, 1_000_000),
                ]));
            } elseif (\strpos($url, '/api/1.0/open/finishes/dependencies/files/uploads') !== false) {
                return new MockResponse('', ['http_code' => 204]);
            } elseif (\strpos($url, '/api/1.0/open/files/supported-formats') !== false) {
                return new MockResponse(<<<'EOD'
[{"regex":"advenica_format\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"apk\\.list","documentationUrl":null,"lockFileRegexes":[]},{"regex":"apt\\.list","documentationUrl":null,"lockFileRegexes":[]},{"regex":"axis_packages\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"((?!WORKSPACE|BUILD)).*(?:\\.bazel)","documentationUrl":null,"lockFileRegexes":[]},{"regex":"((?!WORKSPACE|BUILD)).*(?:\\.bzl)","documentationUrl":null,"lockFileRegexes":[]},{"regex":".*_install\\.json","documentationUrl":null,"lockFileRegexes":[]},{"regex":"WORKSPACE\\.bazel","documentationUrl":null,"lockFileRegexes":[]},{"regex":"WORKSPACE\\.bzl","documentationUrl":null,"lockFileRegexes":[]},{"regex":"WORKSPACE","documentationUrl":null,"lockFileRegexes":[]},{"regex":"bitbake_installed_packages\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"\\.manifest","documentationUrl":null,"lockFileRegexes":[]},{"regex":"debian_descriptions\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"mix\\.lock","documentationUrl":null,"lockFileRegexes":[]},{"regex":"NaNaNa.batman","documentationUrl":null,"lockFileRegexes":[]},{"regex":"flatpak\\.list","documentationUrl":null,"lockFileRegexes":[]},{"regex":"go\\.sum","documentationUrl":null,"lockFileRegexes":["go\\.mod"]},{"regex":"build\\.gradle","documentationUrl":null,"lockFileRegexes":["\\.debricked-gradle-dependencies\\.txt"]},{"regex":"build\\.gradle\\.kts","documentationUrl":null,"lockFileRegexes":["\\.debricked-gradle-dependencies\\.txt"]},{"regex":"pom\\.xml","documentationUrl":null,"lockFileRegexes":["\\.debricked-maven-dependencies\\.tgf"]},{"regex":"bower\\.json","documentationUrl":null,"lockFileRegexes":[]},{"regex":"package\\.json","documentationUrl":null,"lockFileRegexes":["package-lock\\.json","yarn\\.lock"]},{"regex":"npm-shrinkwrap\\.json","documentationUrl":null,"lockFileRegexes":[]},{"regex":".*(?:\\.csproj)","documentationUrl":null,"lockFileRegexes":["packages\\.lock\\.json"]},{"regex":"packages\\.config","documentationUrl":null,"lockFileRegexes":[]},{"regex":"pacman\\.list","documentationUrl":null,"lockFileRegexes":[]},{"regex":"paket\\.lock","documentationUrl":null,"lockFileRegexes":[]},{"regex":"composer\\.json","documentationUrl":null,"lockFileRegexes":["composer\\.lock"]},{"regex":"requirements.*(?:\\.txt)","documentationUrl":null,"lockFileRegexes":[]},{"regex":"Pipfile","documentationUrl":null,"lockFileRegexes":["Pipfile\\.lock"]},{"regex":"manifest_rev_revisions\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"manifest_revisions\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"rootfs_manifest\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"rpm\\.list","documentationUrl":null,"lockFileRegexes":[]},{"regex":"snap\\.list","documentationUrl":null,"lockFileRegexes":[]},{"regex":"\\.debricked-call-graph","documentationUrl":null,"lockFileRegexes":[]},{"regex":"\\.debricked-wfp-fingerprints\\.txt","documentationUrl":null,"lockFileRegexes":[]},{"regex":"Gopkg\\.lock","documentationUrl":null,"lockFileRegexes":[]},{"regex":"Gemfile\\.lock","documentationUrl":null,"lockFileRegexes":[]},{"regex":"Cargo\\.lock","documentationUrl":null,"lockFileRegexes":[]},{"regex":"Podfile\\.lock","documentationUrl":null,"lockFileRegexes":[]}]
EOD
                );
            } else {
                return new MockResponse('', ['http_code' => 404]);
            }
        };

        $mockClient = new MockHttpClient($responseMockGenerator, $_ENV['DEBRICKED_API_URI']);

        $kernel = self::createKernel();
        $application = new Application($kernel);
        $application->add(new FindAndUploadFilesCommand($mockClient));
        $this->command = $application->find(FindAndUploadFilesCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }
}
