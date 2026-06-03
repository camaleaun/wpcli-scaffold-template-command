Feature: wp scaffold template

  Scenario: Scaffold from a local template pack
    Given a WP install
    And a directory exists at {RUN_DIR}/my-template
    And a file exists at {RUN_DIR}/my-template/scaffold.yml:
      """
      name: test/my-template
      description: Test template
      version: 1
      parameters:
        thing_name:
          description: Name of the thing
          default: ~
          derive: "ucwords(slug, '-')"
      computed:
        thing_upper: "strtoupper(slug)"
      variables:
        thing_name: thing_name
        thing_upper: thing_upper
        slug: slug
      files:
        core:
          items:
            - dest: "{{slug}}.txt"
              template: thing.mustache
      """
    And a file exists at {RUN_DIR}/my-template/templates/thing.mustache:
      """
      Name: {{thing_name}}
      Upper: {{thing_upper}}
      Slug: {{slug}}
      """

    When I run `wp scaffold template {RUN_DIR}/my-template hello-world`
    Then STDOUT should contain:
      """
      Created: hello-world.txt
      """
    And the {PLUGIN_DIR}/hello-world/hello-world.txt file should contain:
      """
      Name: Hello World
      """
    And the {PLUGIN_DIR}/hello-world/hello-world.txt file should contain:
      """
      Upper: HELLO-WORLD
      """

  Scenario: Scaffold plugin from camaleaun/wp-scaffold-plugin (local copy)
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}

    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --plugin_author="Jane Doe"`
    Then STDOUT should contain:
      """
      Success:
      """
    And the {PLUGIN_DIR}/my-plugin/my-plugin.php file should exist
    And the {PLUGIN_DIR}/my-plugin/src/Autoloader.php file should exist
    And the {PLUGIN_DIR}/my-plugin/src/Packages.php file should exist
    And the {PLUGIN_DIR}/my-plugin/src/Constants.php file should exist
    And the {PLUGIN_DIR}/my-plugin/composer.json file should exist
    And the {PLUGIN_DIR}/my-plugin/phpunit.xml file should exist
    And the {PLUGIN_DIR}/my-plugin/tests/bootstrap.php file should exist
    And the {PLUGIN_DIR}/my-plugin/.github/workflows/tests.yml file should exist
    And the {PLUGIN_DIR}/my-plugin/my-plugin.php file should contain:
      """
      Plugin Name:
      """
    And the {PLUGIN_DIR}/my-plugin/my-plugin.php file should contain:
      """
      Author:            Jane Doe
      """

  Scenario: Variables mapping — plugin_name placeholder uses plugin_name parameter
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}

    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --plugin_name="Awesome Plugin"`
    Then the {PLUGIN_DIR}/my-plugin/my-plugin.php file should contain:
      """
      Plugin Name:       Awesome Plugin
      """
    And the {PLUGIN_DIR}/my-plugin/src/Autoloader.php file should contain:
      """
      namespace Awesome\Plugin;
      """
    And the {PLUGIN_DIR}/my-plugin/composer.json file should contain:
      """
      "Awesome\\Plugin\\": "src/"
      """

  Scenario: --skip-tests omits test files
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}

    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --skip-tests`
    Then the {PLUGIN_DIR}/my-plugin/my-plugin.php file should exist
    And the {PLUGIN_DIR}/my-plugin/tests directory should not exist
    And the {PLUGIN_DIR}/my-plugin/phpunit.xml file should not exist

  Scenario: --force overwrites existing files
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}

    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin`
    And I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --force`
    Then STDOUT should contain:
      """
      Success:
      """

  Scenario: vendor/repo with --git=gitlab uses gitlab.com
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}

    When I try `wp scaffold template acme/some-tpl my-thing --git=gitlab`
    Then STDERR should contain:
      """
      gitlab.com
      """

  Scenario: Bare repo with --owner resolves to vendor/repo
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}

    When I try `wp scaffold template plugin my-plugin --owner=camaleaun`
    Then STDOUT should contain:
      """
      camaleaun/plugin
      """

  Scenario: Bare repo + --repo-prefix builds full repo name
    Given a WP install

    When I try `wp scaffold template plugin my-plugin --owner=camaleaun --repo-prefix=wp-scaffold-`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-plugin
      """

  Scenario: Bare repo + --repo-pattern builds full repo name
    Given a WP install

    When I try `wp scaffold template plugin my-plugin --owner=camaleaun --repo-pattern=wp-scaffold-*`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-plugin
      """

  Scenario: --repo-pattern takes precedence over --repo-prefix
    Given a WP install

    When I try `wp scaffold template plugin my-plugin --owner=camaleaun --repo-prefix=ignored- --repo-pattern=wp-scaffold-*`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-plugin
      """
    And STDOUT should not contain:
      """
      ignored-
      """

  Scenario: Bare repo without --owner produces error
    Given a WP install

    When I try `wp scaffold template plugin my-plugin`
    Then STDERR should contain:
      """
      Error: Template 'plugin' has no owner
      """
    And the return code should be 1

  Scenario: Invalid template reference produces error
    Given a WP install

    When I try `wp scaffold template not/valid/path my-plugin`
    Then STDERR should contain:
      """
      Error: Invalid template reference
      """
    And the return code should be 1

  Scenario: wp-cli.yml defaults for owner and repo-pattern
    Given a WP install
    And a wp-cli.yml file:
      """
      scaffold template:
        owner: camaleaun
        repo-pattern: wp-scaffold-*
      """

    When I try `wp scaffold template plugin my-plugin`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-plugin
      """

  Scenario: scaffold.yml without files section produces error
    Given a WP install
    And a directory exists at {RUN_DIR}/bad-template
    And a file exists at {RUN_DIR}/bad-template/scaffold.yml:
      """
      name: bad/template
      description: Missing files section
      version: 1
      """

    When I try `wp scaffold template {RUN_DIR}/bad-template my-thing`
    Then STDERR should contain:
      """
      Error: scaffold.yml must define at least one file group under `files:`
      """
    And the return code should be 1
