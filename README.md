# debricked-cli
[![Build Status](https://travis-ci.org/debricked/debricked-cli.svg?branch=master)](https://travis-ci.org/debricked/debricked-cli)
[![Latest Stable Version](https://poser.pugx.org/debricked/cli/v/stable)](https://packagist.org/packages/debricked/cli)

![Debricked CLI in action](debricked-cli.png)

Command Line Tool (CLI) for interacting with [Debricked](https://debricked.com). Supports uploading and checking your dependency files for vulnerabilities.

## Documentation
Head over to our [Integration documentation page](https://debricked.com/knowledge-base/articles/integrations/#debricked-cli) for the main source of documentation.

To run the tool using only Docker, instead of a local install, use it as below,
where the current directory is assumed to contain the project you wish to scan.

```
docker run -it --rm -v $PWD:/data debricked/debricked-cli <command>
```

A practical example of scanning a local repository in your current working directory:

```
docker run -it --rm -v $PWD:/data debricked/debricked-cli debricked:scan user@example.com password myrepository mycommit null cli
```

To be clear, you need to modify these parts of the command:

* `user@example.com` and `password`: Replace with your e-mail and password to the service.
* `myrepository`: Replace with the name of the repository.
* `mycommit`: A unique identifier (for example the commit hash in Git) for this particular commit.

You do not need to replace `null cli`. It is simply a marked used by the server to distinguish between different integrations.

If you are building your CI pipeline integration, you can typically get `myrepository` and `mycommit` as environmental variables from you CI system.

### If you use languages that need a copy of the whole repository

In most cases, such as above, the tool only needs to upload your dependency files to the service.
However, [for certain languages](https://debricked.com/documentation/1.0/language-support/language-support), you may need to upload a complete copy of the repository.
You then need to add the `--upload-all-files=true` to the command, such as in the following example.

```
docker run -it --rm -v $PWD:/data debricked/debricked-cli debricked:scan --upload-all-files=true user@example.com password myrepository mycommit null cli
```

### If you have an on-premise solution

For customers with a deployed on-premise solution, you also need to modify the destination server. You can do this by setting the `DEBRICKED_API_URI` environmental variable to your own server, as in the example command below:

```
docker run -it --rm -e DEBRICKED_API_URI=https://your.on.prem.server -v $PWD:/data debricked/debricked-cli debricked:scan user@example.com password myrepository mycommit null cli
```

## Code contributions

### Build image for running the tool

To build the cli tool for running

```
docker build -t debricked/debricked-cli .
```

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
