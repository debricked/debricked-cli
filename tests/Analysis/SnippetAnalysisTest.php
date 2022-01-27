<?php

namespace App\Tests\Analysis;

use App\Analysis\SnippetAnalysis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SnippetAnalysisTest extends KernelTestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function testEmptyState()
    {
        $analyser = new SnippetAnalysis();

        $this->assertEquals('', $analyser->dumpWfp());
    }

    public function testIgnoresZipAndNoExtension()
    {
        $analyser = new SnippetAnalysis();
        $this->assertFalse($analyser->analyseFile('tests/nonexistent.zip', 'nonexistent.zip'));
        $this->assertFalse($analyser->analyseFile('./COPYING', 'COPYING'));
    }

    public function testSingleFile()
    {
        $analyser = new SnippetAnalysis();
        $analyser->analyseFile('tests/Analysis/TestFiles/TestSourceCombinedOutput.php', 'TestFiles/TestSourceCombinedOutput.php');

        $this->assertEquals("file=b38805b9e7cddd1da4a3f7a7290b0c6a,1075,TestFiles/TestSourceCombinedOutput.php
8=514cd405,b60d2d79,ee688409
11=d7d1f600,fffc9255
13=17378c05
14=b0d48124
17=7c7f1dd2,99768710
19=d106e16a
24=de48239e,82b50939
27=d4117a3d
33=6df123bf
34=1bc148e9
42=0887f549
46=bafa66f2
47=99deae63
50=3bb2dc41,6a87c3c1\n", $analyser->dumpWfp());
    }

    public function testTwoFiles()
    {
        $analyser = new SnippetAnalysis();
        $analyser->analyseFile('tests/Analysis/TestFiles/TestSourceCombinedOutput.php', 'TestFiles/TestSourceCombinedOutput.php');
        $analyser->analyseFile('tests/Analysis/TestFiles/TestSourceKernel.php', 'TestFiles/TestSourceKernel.php');

        $this->assertEquals("file=b38805b9e7cddd1da4a3f7a7290b0c6a,1075,TestFiles/TestSourceCombinedOutput.php
8=514cd405,b60d2d79,ee688409
11=d7d1f600,fffc9255
13=17378c05
14=b0d48124
17=7c7f1dd2,99768710
19=d106e16a
24=de48239e,82b50939
27=d4117a3d
33=6df123bf
34=1bc148e9
42=0887f549
46=bafa66f2
47=99deae63
50=3bb2dc41,6a87c3c1
file=124220b2379d938f2ce5eebf9e301701,1886,TestFiles/TestSourceKernel.php
6=de02c71a
7=792c86aa
8=48657d5f,9d8906fd,054a2a56
9=33d7d949
12=f68b33bd
14=0ad1b6c4,db097fa4
16=b8bbdad2
18=7a589a15
20=4288f180
21=bb70dade
23=566575f9
28=ba66733c,4995e78c
30=7751090e,703ccf30
32=a15fc2b5,3d711bd3
35=201f14e2
37=0b3ac7e3
40=20184ce0
42=84eff26c
44=0332e9fe,3c5aafbf
45=21d8d422
46=6287c646\n", $analyser->dumpWfp());
    }
}
