# debricked-cli
[![Build Status](https://travis-ci.org/debricked/debricked-cli.svg?branch=master)](https://travis-ci.org/debricked/debricked-cli)
[![Latest Stable Version](https://poser.pugx.org/debricked/cli/v/stable)](https://packagist.org/packages/debricked/cli)

![Debricked CLI in action](debricked-cli.png)

Command Line Tool (CLI) for interacting with [Debricked](https://debricked.com). Supports uploading and checking your dependency files for vulnerabilities.

## Documentation
Head over to our [Integration documentation page](https://debricked.com/knowledge-base/articles/integrations/#debricked-cli).

## Code contributions

### Run tests
All contributions are greatly welcome! To help you get started we have a included a
Dockerfile which provides a environment capable of running the whole CLI application
and related tests.

#### Prerequisites
- [Docker](https://docs.docker.com/install/)

#### Configure and run test environment
1. Create a .env.test.local file in the root directory (alongside this README) containing:
```text
DEBRICKED_USERNAME=your debricked username
DEBRICKED_PASSWORD=your debricked password
```
2. Run tests! You can now run the tests locally by executing `./localTest.sh` in your terminal.

### Best practises
We try to follow Symfony's best practises as much as possible when developing. You can read more about them here
https://symfony.com/doc/current/best_practices/business-logic.html
