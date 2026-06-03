Feature: wp scaffold template

  # ── Template reference resolution ─────────────────────────────────────────────

  Scenario: Local relative path
    Given a WP install
    And a directory exists at {RUN_DIR}/local-tpl
    And a file exists at {RUN_DIR}/local-tpl/scaffold.yml:
      """
      name: test/local
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}.txt"
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/local-tpl/out.mustache:
      """
      slug={{slug}}
      """
    When I run `wp scaffold template {RUN_DIR}/local-tpl hello`
    Then STDOUT should contain:
      """
      Created: hello.txt
      """

  Scenario: Local path that does not exist produces error
    Given a WP install
    When I try `wp scaffold template ./no-such-dir my-thing`
    Then STDERR should contain:
      """
      Error: Local template path not found
      """
    And the return code should be 1

  Scenario: vendor/repo uses GitHub by default
    Given a WP install
    When I try `wp scaffold template camaleaun/wp-scaffold-plugin my-plugin`
    Then STDOUT should contain:
      """
      github.com/camaleaun/wp-scaffold-plugin
      """

  Scenario: vendor/repo with --git=gitlab uses gitlab.com
    Given a WP install
    When I try `wp scaffold template acme/my-tpl my-thing --git=gitlab`
    Then STDOUT should contain:
      """
      gitlab.com/acme/my-tpl
      """

  Scenario: vendor/repo with --git=bitbucket uses bitbucket.org
    Given a WP install
    When I try `wp scaffold template acme/my-tpl my-thing --git=bitbucket`
    Then STDOUT should contain:
      """
      bitbucket.org/acme/my-tpl
      """

  Scenario: vendor/repo@ref pins to a specific ref
    Given a WP install
    When I try `wp scaffold template camaleaun/wp-scaffold-plugin@1.0.0 my-plugin`
    Then STDOUT should contain:
      """
      1.0.0
      """

  Scenario: Full HTTPS URL is used as-is
    Given a WP install
    When I try `wp scaffold template https://github.com/camaleaun/wp-scaffold-plugin my-plugin`
    Then STDOUT should contain:
      """
      https://github.com/camaleaun/wp-scaffold-plugin
      """

  Scenario: Full HTTPS URL with @ref pins to ref
    Given a WP install
    When I try `wp scaffold template https://github.com/camaleaun/wp-scaffold-plugin@1.0.0 my-plugin`
    Then STDOUT should contain:
      """
      1.0.0
      """

  Scenario: Full SSH URL GitHub
    Given a WP install
    When I try `wp scaffold template git@github.com:camaleaun/wp-scaffold-plugin.git my-plugin`
    Then STDOUT should contain:
      """
      git@github.com:camaleaun/wp-scaffold-plugin.git
      """

  Scenario: Full SSH URL GitLab
    Given a WP install
    When I try `wp scaffold template git@gitlab.com:acme/my-tpl.git my-thing`
    Then STDOUT should contain:
      """
      gitlab.com
      """

  Scenario: Bare repo with --owner resolves to vendor/repo
    Given a WP install
    When I try `wp scaffold template plugin my-plugin --owner=camaleaun`
    Then STDOUT should contain:
      """
      camaleaun/plugin
      """

  Scenario: Bare repo + --owner + --repo-pattern with prefix
    Given a WP install
    When I try `wp scaffold template plugin my-plugin --owner=camaleaun --repo-pattern=wp-scaffold-{}`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-plugin
      """

  Scenario: --repo-pattern with suffix
    Given a WP install
    When I try `wp scaffold template plugin my-plugin --owner=acme --repo-pattern={}-scaffold`
    Then STDOUT should contain:
      """
      acme/plugin-scaffold
      """

  Scenario: --repo-pattern with prefix and suffix
    Given a WP install
    When I try `wp scaffold template block my-block --owner=acme --repo-pattern=my-{}-tpl`
    Then STDOUT should contain:
      """
      acme/my-block-tpl
      """

  Scenario: --repo-pattern={} is identity (no transformation)
    Given a WP install
    When I try `wp scaffold template my-plugin my-plugin --owner=acme --repo-pattern={}`
    Then STDOUT should contain:
      """
      acme/my-plugin
      """

  Scenario: Bare repo without --owner produces error
    Given a WP install
    When I try `wp scaffold template plugin my-plugin`
    Then STDERR should contain:
      """
      Error: Template 'plugin' has no owner
      """
    And the return code should be 1

  Scenario: Invalid vendor/repo/extra format produces error
    Given a WP install
    When I try `wp scaffold template not/valid/extra my-plugin`
    Then STDERR should contain:
      """
      Error: Invalid template reference
      """
    And the return code should be 1

  Scenario: wp-cli.yml sets owner, git and repo-pattern as defaults
    Given a WP install
    And a wp-cli.yml file:
      """
      scaffold template:
        owner: camaleaun
        git: github
        repo-pattern: wp-scaffold-{}
      """
    When I try `wp scaffold template plugin my-plugin`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-plugin
      """

  Scenario: CLI flag overrides wp-cli.yml default owner
    Given a WP install
    And a wp-cli.yml file:
      """
      scaffold template:
        owner: camaleaun
        repo-pattern: wp-scaffold-{}
      """
    When I try `wp scaffold template plugin my-plugin --owner=acme`
    Then STDOUT should contain:
      """
      acme/wp-scaffold-plugin
      """

  # ── scaffold.yml validation ────────────────────────────────────────────────────

  Scenario: scaffold.yml not found produces error
    Given a WP install
    And a directory exists at {RUN_DIR}/empty-tpl
    When I try `wp scaffold template {RUN_DIR}/empty-tpl my-thing`
    Then STDERR should contain:
      """
      Error: scaffold.yml not found
      """
    And the return code should be 1

  Scenario: scaffold.yml without files section produces error
    Given a WP install
    And a directory exists at {RUN_DIR}/bad-tpl
    And a file exists at {RUN_DIR}/bad-tpl/scaffold.yml:
      """
      name: bad/template
      version: 1
      """
    When I try `wp scaffold template {RUN_DIR}/bad-tpl my-thing`
    Then STDERR should contain:
      """
      Error: scaffold.yml must define at least one file group under `files:`
      """
    And the return code should be 1

  Scenario: Missing template file produces warning and continues with remaining files
    Given a WP install
    And a directory exists at {RUN_DIR}/broken-tpl
    And a file exists at {RUN_DIR}/broken-tpl/scaffold.yml:
      """
      name: test/broken
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}-missing.txt"
              template: missing.mustache
            - dest: "{{slug}}-ok.txt"
              template: ok.mustache
      """
    And a file exists at {RUN_DIR}/broken-tpl/ok.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/broken-tpl my-thing`
    Then STDERR should contain:
      """
      Warning: Template file not found, skipping: missing.mustache
      """
    And STDOUT should contain:
      """
      Created: my-thing-ok.txt
      """

  # ── File generation ────────────────────────────────────────────────────────────

  Scenario: dest is Mustache-rendered with slug
    Given a WP install
    And a directory exists at {RUN_DIR}/dest-tpl
    And a file exists at {RUN_DIR}/dest-tpl/scaffold.yml:
      """
      name: test/dest
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}.php"
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/dest-tpl/out.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/dest-tpl my-plugin`
    Then STDOUT should contain:
      """
      Created: my-plugin.php
      """

  Scenario: Existing file without --force is skipped
    Given a WP install
    And a directory exists at {RUN_DIR}/skip-existing-tpl
    And a file exists at {RUN_DIR}/skip-existing-tpl/scaffold.yml:
      """
      name: test/skip-existing
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}.txt"
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/skip-existing-tpl/out.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/skip-existing-tpl my-thing`
    And I run `wp scaffold template {RUN_DIR}/skip-existing-tpl my-thing`
    Then STDOUT should contain:
      """
      Skipped (already exists): my-thing.txt
      """

  Scenario: --force overwrites existing files
    Given a WP install
    And a directory exists at {RUN_DIR}/force-tpl
    And a file exists at {RUN_DIR}/force-tpl/scaffold.yml:
      """
      name: test/force
      version: 1      files:
        core:
          items:
            - dest: "{{slug}}.txt"
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/force-tpl/out.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/force-tpl my-thing`
    And I run `wp scaffold template {RUN_DIR}/force-tpl my-thing --force`
    Then STDOUT should contain:
      """
      Created: my-thing.txt
      """
    And STDOUT should not contain:
      """
      Skipped
      """

  Scenario: --dir places output outside plugins directory
    Given a WP install
    And a directory exists at {RUN_DIR}/dir-tpl
    And a file exists at {RUN_DIR}/dir-tpl/scaffold.yml:
      """
      name: test/dir
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}.txt"
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/dir-tpl/out.mustache:
      """
      ok
      """
    And a directory exists at {RUN_DIR}/custom-out
    When I run `wp scaffold template {RUN_DIR}/dir-tpl my-thing --dir={RUN_DIR}/custom-out`
    Then the {RUN_DIR}/custom-out/my-thing/my-thing.txt file should exist

  Scenario: --dir that does not exist produces error
    Given a WP install
    And a directory exists at {RUN_DIR}/dir-tpl
    And a file exists at {RUN_DIR}/dir-tpl/scaffold.yml:
      """
      name: test/dir
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}.txt"
              template: out.mustache
      """
    When I try `wp scaffold template {RUN_DIR}/dir-tpl my-thing --dir={RUN_DIR}/no-such-dir`
    Then STDERR should contain:
      """
      Error: Directory does not exist
      """
    And the return code should be 1

  # ── Variable resolution ────────────────────────────────────────────────────────

  Scenario: slug is always available as positional argument
    Given a WP install
    And a directory exists at {RUN_DIR}/slug-tpl
    And a file exists at {RUN_DIR}/slug-tpl/scaffold.yml:
      """
      name: test/slug
      version: 1
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/slug-tpl/out.mustache:
      """
      {{slug}}
      """
    When I run `wp scaffold template {RUN_DIR}/slug-tpl my-thing`
    Then the output file out.txt should contain:
      """
      my-thing
      """

  Scenario: Parameter default is used when flag not passed
    Given a WP install
    And a directory exists at {RUN_DIR}/default-tpl
    And a file exists at {RUN_DIR}/default-tpl/scaffold.yml:
      """
      name: test/default
      version: 1
      parameters:
        color:
          default: blue
      variables:
        color: color
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/default-tpl/out.mustache:
      """
      {{color}}
      """
    When I run `wp scaffold template {RUN_DIR}/default-tpl my-thing`
    Then the output file out.txt should contain:
      """
      blue
      """

  Scenario: CLI flag overrides parameter default
    Given a WP install
    And a directory exists at {RUN_DIR}/override-tpl
    And a file exists at {RUN_DIR}/override-tpl/scaffold.yml:
      """
      name: test/override
      version: 1
      parameters:
        color:
          default: blue
      variables:
        color: color
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/override-tpl/out.mustache:
      """
      {{color}}
      """
    When I run `wp scaffold template {RUN_DIR}/override-tpl my-thing --color=red`
    Then the output file out.txt should contain:
      """
      red
      """

  Scenario: derive fills null default from slug
    Given a WP install
    And a directory exists at {RUN_DIR}/derive-tpl
    And a file exists at {RUN_DIR}/derive-tpl/scaffold.yml:
      """
      name: test/derive
      version: 1
      parameters:
        plugin_name:
          default: ~
          derive: "ucwords(slug, '-')"
      variables:
        plugin_name: plugin_name
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/derive-tpl/out.mustache:
      """
      {{plugin_name}}
      """
    When I run `wp scaffold template {RUN_DIR}/derive-tpl my-awesome-plugin`
    Then the output file out.txt should contain:
      """
      My Awesome Plugin
      """

  Scenario: derive is skipped when flag is explicitly passed
    Given a WP install
    And a directory exists at {RUN_DIR}/derive-skip-tpl
    And a file exists at {RUN_DIR}/derive-skip-tpl/scaffold.yml:
      """
      name: test/derive-skip
      version: 1
      parameters:
        plugin_name:
          default: ~
          derive: "ucwords(slug, '-')"
      variables:
        plugin_name: plugin_name
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/derive-skip-tpl/out.mustache:
      """
      {{plugin_name}}
      """
    When I run `wp scaffold template {RUN_DIR}/derive-skip-tpl my-plugin --plugin_name="Custom Name"`
    Then the output file out.txt should contain:
      """
      Custom Name
      """

  Scenario: computed is evaluated after all parameters are resolved
    Given a WP install
    And a directory exists at {RUN_DIR}/computed-tpl
    And a file exists at {RUN_DIR}/computed-tpl/scaffold.yml:
      """
      name: test/computed
      version: 1
      parameters:
        plugin_name:
          default: ~
          derive: "ucwords(slug, '-')"
      computed:
        plugin_upper: "strtoupper(plugin_name)"
      variables:
        plugin_name: plugin_name
        plugin_upper: plugin_upper
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/computed-tpl/out.mustache:
      """
      {{plugin_name}}|{{plugin_upper}}
      """
    When I run `wp scaffold template {RUN_DIR}/computed-tpl my-plugin`
    Then the output file out.txt should contain:
      """
      My Plugin|MY PLUGIN
      """

  Scenario: computed concatenation operator ~
    Given a WP install
    And a directory exists at {RUN_DIR}/concat-tpl
    And a file exists at {RUN_DIR}/concat-tpl/scaffold.yml:
      """
      name: test/concat
      version: 1
      parameters:
        plugin_name:
          default: ~
          derive: "ucwords(slug, '-')"
      computed:
        ns_test: "plugin_name ~ '\\\\Tests'"
      variables:
        ns_test: ns_test
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/concat-tpl/out.mustache:
      """
      {{ns_test}}
      """
    When I run `wp scaffold template {RUN_DIR}/concat-tpl my-plugin`
    Then the output file out.txt should contain:
      """
      My Plugin\Tests
      """

  Scenario: variables mapping renames placeholder
    Given a WP install
    And a directory exists at {RUN_DIR}/vars-tpl
    And a file exists at {RUN_DIR}/vars-tpl/scaffold.yml:
      """
      name: test/vars
      version: 1
      parameters:
        author_name:
          default: Jane
      variables:
        display_author: author_name
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/vars-tpl/out.mustache:
      """
      {{display_author}}
      """
    When I run `wp scaffold template {RUN_DIR}/vars-tpl my-thing`
    Then the output file out.txt should contain:
      """
      Jane
      """

  Scenario: placeholder without variables entry resolves by name
    Given a WP install
    And a directory exists at {RUN_DIR}/direct-tpl
    And a file exists at {RUN_DIR}/direct-tpl/scaffold.yml:
      """
      name: test/direct
      version: 1
      parameters:
        greeting:
          default: hello
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/direct-tpl/out.mustache:
      """
      {{greeting}}
      """
    When I run `wp scaffold template {RUN_DIR}/direct-tpl my-thing`
    Then the output file out.txt should contain:
      """
      hello
      """

  # ── File group behaviour ───────────────────────────────────────────────────────

  Scenario: skip_when flag skips entire group
    Given a WP install
    And a directory exists at {RUN_DIR}/skipgroup-tpl
    And a file exists at {RUN_DIR}/skipgroup-t
pl/scaffold.yml:
      """
      name: test/skipgroup
      version: 1
      parameters:
        skip-extras:
          type: flag
          default: false
      files:
        core:
          items:
            - dest: core.txt
              template: out.mustache
        extras:
          skip_when: { flag: skip-extras }
          items:
            - dest: extras.txt
              template: out.mustache
      """
    And a file exists at {RUN_DIR}/skipgroup-tpl/out.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/skipgroup-tpl my-thing --skip-extras`
    Then STDOUT should contain:
      """
      Created: core.txt
      """
    And STDOUT should not contain:
      """
      extras.txt
      """

  Scenario: select_by param emits only the matching item
    Given a WP install
    And a directory exists at {RUN_DIR}/select-tpl
    And a file exists at {RUN_DIR}/select-tpl/scaffold.yml:
      """
      name: test/select
      version: 1
      parameters:
        ci:
          default: github
          options: [github, gitlab]
      files:
        ci:
          select_by: { param: ci }
          items:
            - dest: github-ci.txt
              template: out.mustache
              when: github
            - dest: gitlab-ci.txt
              template: out.mustache
              when: gitlab
      """
    And a file exists at {RUN_DIR}/select-tpl/out.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/select-tpl my-thing --ci=gitlab`
    Then STDOUT should contain:
      """
      Created: gitlab-ci.txt
      """
    And STDOUT should not contain:
      """
      github-ci.txt
      """

  Scenario: select_by uses default when flag not passed
    Given a WP install
    And a directory exists at {RUN_DIR}/select-default-tpl
    And a file exists at {RUN_DIR}/select-default-tpl/scaffold.yml:
      """
      name: test/select-default
      version: 1
      parameters:
        ci:
          default: github
          options: [github, gitlab]
      files:
        ci:
          select_by: { param: ci }
          items:
            - dest: github-ci.txt
              template: out.mustache
              when: github
            - dest: gitlab-ci.txt
              template: out.mustache
              when: gitlab
      """
    And a file exists at {RUN_DIR}/select-default-tpl/out.mustache:
      """
      ok
      """
    When I run `wp scaffold template {RUN_DIR}/select-default-tpl my-thing`
    Then STDOUT should contain:
      """
      Created: github-ci.txt
      """
    And STDOUT should not contain:
      """
      gitlab-ci.txt
      """

  # ── Post-actions ───────────────────────────────────────────────────────────────

  Scenario: post_action plugin_activate activates after scaffold
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}
    And a directory exists at {RUN_DIR}/activate-tpl
    And a file exists at {RUN_DIR}/activate-tpl/scaffold.yml:
      """
      name: test/activate
      version: 1
      parameters:
        activate:
          type: flag
          default: false
      files:
        core:
          items:
            - dest: "{{slug}}.php"
              template: plugin.mustache
      post_actions:
        - action: plugin_activate
          when: { flag: activate }
      """
    And a file exists at {RUN_DIR}/activate-tpl/plugin.mustache:
      """
      <?php
      /* Plugin Name: Test Activate */
      """
    When I run `wp scaffold template {RUN_DIR}/activate-tpl my-activate-plugin --activate`
    Then STDOUT should contain:
      """
      Success:
      """
    When I run `wp plugin status my-activate-plugin`
    Then STDOUT should contain:
      """
      Status: Active
      """

  Scenario: post_action is skipped when flag not passed
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}
    And a directory exists at {RUN_DIR}/no-activate-tpl
    And a file exists at {RUN_DIR}/no-activate-tpl/scaffold.yml:
      """
      name: test/no-activate
      version: 1
      parameters:
        activate:
          type: flag
          default: false
      files:
        core:
          items:
            - dest: "{{slug}}.php"
              template: plugin.mustache
      post_actions:
        - action: plugin_activate
          when: { flag: activate }
      """
    And a file exists at {RUN_DIR}/no-activate-tpl/plugin.mustache:
      """
      <?php
      /* Plugin Name: Test No Activate */
      """
    When I run `wp scaffold template {RUN_DIR}/no-activate-tpl my-no-activate-plugin`
    When I run `wp plugin status my-no-activate-plugin`
    Then STDOUT should contain:
      """
      Status: Inactive
      """

  # ── Subpath (multi-pack repos) ────────────────────────────────────────────────

  Scenario: Local multi-pack via :subpath selects plugin pack
    Given a WP install
    And a directory exists at {RUN_DIR}/multi-pack/plugin
    And a file exists at {RUN_DIR}/multi-pack/plugin/scaffold.yml:
      """
      name: test/plugin
      version: 1
      files:
        core:
          items:
            - dest: "{{slug}}.php"
              template: plugin.mustache
      """
    And a file exists at {RUN_DIR}/multi-pack/plugin/plugin.mustache:
      """
      plugin
      """
    And a directory exists at {RUN_DIR}/multi-pack/theme
    And a file exists at {RUN_DIR}/multi-pack/theme/scaffold.yml:
      """
      name: test/theme
      version: 1
      files:
        core:
          items:
            - dest: style.css
              template: style.mustache
      """
    And a file exists at {RUN_DIR}/multi-pack/theme/style.mustache:
      """
      theme
      """
    When I run `wp scaffold template {RUN_DIR}/multi-pack:plugin my-plugin`
    Then STDOUT should contain:
      """
      Created: my-plugin.php
      """
    And STDOUT should not contain:
      """
      style.css
      """

  Scenario: Local multi-pack :theme subpath
    Given a WP install
    And a directory exists at {RUN_DIR}/multi2/theme
    And a file exists at {RUN_DIR}/multi2/theme/scaffold.yml:
      """
      name: test/theme
      version: 1
      files:
        core:
          items:
            - dest: style.css
              template: style.mustache
      """
    And a file exists at {RUN_DIR}/multi2/theme/style.mustache:
      """
      theme
      """
    When I run `wp scaffold template {RUN_DIR}/multi2:theme my-theme`
    Then STDOUT should contain:
      """
      Created: style.css
      """

  Scenario: :subpath that does not exist produces error
    Given a WP install
    And a directory exists at {RUN_DIR}/multi3
    And a file exists at {RUN_DIR}/multi3/scaffold.yml:
      """
      name: test/root
      version: 1
      files:
        core:
          items:
            - dest: out.txt
              template: out.mustache
      """
    When I try `wp scaffold template {RUN_DIR}/multi3:no-such-subpath my-thing`
    Then STDERR should contain:
      """
      Error: Subpath not found in template pack: no-such-subpath
      """
    And the return code should be 1

  Scenario: vendor/repo:subpath in short ref
    Given a WP install
    When I try `wp scaffold template camaleaun/wp-scaffold-wp:plugin my-plugin`
    Then STDOUT should contain:
      """
      camaleaun/wp-scaffold-wp
      """

  Scenario: vendor/repo@ref:subpath combines pin and subpath
    Given a WP install
    When I try `wp scaffold template camaleaun/wp-scaffold-wp@1.0.0:plugin my-plugin`
    Then STDOUT should contain:
      """
      1.0.0
      """

  Scenario: Full HTTPS URL with @ref:subpath
    Given a WP install
    When I try `wp scaffold template https://github.com/camaleaun/wp-scaffold-wp@1.0.0:plugin my-plugin`
    Then STDOUT should contain:
      """
      1.0.0
      """

  Scenario: SSH URL with @ref:subpath
    Given a WP install
    When I try `wp scaffold template git@github.com:camaleaun/wp-scaffold-wp.git@1.0.0:plugin my-plugin`
    Then STDOUT should contain:
      """
      1.0.0
      """

    # ── wp-scaffold-plugin integration ────────────────────────────────────────────

  Scenario: Full scaffold from camaleaun/wp-scaffold-plugin (local copy)
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
      Author:            Jane Doe
      """

  Scenario: plugin_name placeholder derives from slug
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}
    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin`
    Then the {PLUGIN_DIR}/my-plugin/my-plugin.php file should contain:
      """
      Plugin Name:       My Plugin
      """

  Scenario: plugin_name placeholder uses explicit flag
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

  Scenario: --skip-tests omits test file group
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}
    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --skip-tests`
    Then the {PLUGIN_DIR}/my-plugin/my-plugin.php file should exist
    And the {PLUGIN_DIR}/my-plugin/tests directory should not exist
    And the {PLUGIN_DIR}/my-plugin/phpunit.xml file should not exist

  Scenario: --ci=github generates GitHub Actions workflow
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}
    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --ci=github`
    Then the {PLUGIN_DIR}/my-plugin/.github/workflows/tests.yml file should exist
    And the {PLUGIN_DIR}/my-plugin/.gitlab-ci.yml file should not exist

  Scenario: --ci=gitlab generates GitLab CI file
    Given a WP install
    And I run `wp plugin path`
    And save STDOUT as {PLUGIN_DIR}
    When I run `wp scaffold template {SCAFFOLD_PLUGIN_PACK} my-plugin --ci=gitlab`
    Then the {PLUGIN_DIR}/my-plugin/.gitlab-ci.yml file should exist
    And the {PLUGIN_DIR}/my-plugin/.github/workflows/tests.yml file should not exist
