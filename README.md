Laravel MongoDB
===============

[![Latest Stable Version](http://img.shields.io/github/release/mongodb/laravel-mongodb.svg)](https://packagist.org/packages/mongodb/laravel-mongodb)
[![Total Downloads](http://img.shields.io/packagist/dm/mongodb/laravel-mongodb.svg)](https://packagist.org/packages/mongodb/laravel-mongodb)
[![Build Status](https://img.shields.io/github/actions/workflow/status/mongodb/laravel-mongodb/build-ci.yml)](https://github.com/mongodb/laravel-mongodb/actions/workflows/build-ci.yml)

This package adds functionalities to the Eloquent model and Query builder for MongoDB, using the original Laravel API.
*This library extends the original Laravel classes, so it uses exactly the same methods.*

This package was renamed to `mongodb/laravel-mongodb` because of a transfer of ownership to MongoDB, Inc.
It is compatible with Laravel 10.x. For older versions of Laravel, please refer to the
[old versions](https://github.com/mongodb/laravel-mongodb/tree/3.9#laravel-version-compatibility).

- [Installation](docs/install.md)
- [Eloquent Models](docs/eloquent-models.md)
- [Query Builder](docs/query-builder.md)
- [Transactions](docs/transactions.md)
- [User Authentication](docs/user-authentication.md)
- [Queues](docs/queues.md)
- [Upgrading](docs/upgrade.md)

## Reporting Issues

Think you’ve found a bug in the library? Want to see a new feature? Please open a case in our issue management tool, JIRA:

- [Create an account and login.](https://jira.mongodb.org/)
- Navigate to the [PHPORM](https://jira.mongodb.org/browse/PHPORM) project.
- Click Create - Please provide as much information as possible about the issue type and how to reproduce it.

Note: All reported issues in JIRA project are public.

For general questions and support requests, please use one of MongoDB's
[Technical Support](https://mongodb.com/docs/manual/support/) channels.

### Security Vulnerabilities

If you've identified a security vulnerability in a driver or any other MongoDB
project, please report it according to the instructions in
[Create a Vulnerability Report](https://mongodb.com/docs/manual/tutorial/create-a-vulnerability-report).

## Development

Development is tracked in the
[PHPORM](https://jira.mongodb.org/projects/PHPORM/summary) project in MongoDB's
JIRA. Documentation for contributing to this project may be found in
[CONTRIBUTING.md](CONTRIBUTING.md).
