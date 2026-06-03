<?php

use WP_CLI\Utils;

/**
 * Generates project scaffolding from a remote template pack.
 *
 * A template pack is any Git repository (or local directory) containing
 * a `scaffold.yml` and a `templates/` directory with Mustache files.
 *
 * Both Mustache (Mustache_Engine) and YAML (Mustangostang\Spyc) are bundled
 * inside wp-cli.phar — no extra Composer dependencies required in this package.
 *
 * ## EXAMPLES
 *
 *     # Full vendor/repo — GitHub by default
 *     $ wp scaffold template camaleaun/wp-scaffold-plugin my-plugin
 *
 *     # Repo only — resolves with --owner + --repo-pattern from wp-cli.yml
 *     $ wp scaffold template plugin my-plugin
 *
 *     # Full remote URL (GitHub / GitLab / Bitbucket, HTTPS or SSH)
 *     $ wp scaffold template https://github.com/camaleaun/wp-scaffold-plugin my-plugin
 *     $ wp scaffold template git@gitlab.com:acme/wp-scaffold-plugin.git my-plugin
 *
 *     # Local path
 *     $ wp scaffold template ./my-template my-thing
 *
 * @package camaleaun/wpcli-scaffold-template-command
 */
class Camaleaun_Scaffold_Template_Command extends WP_CLI_Command {

	/**
	 * Local cache directory for cloned template packs.
	 *
	 * @var string
	 */
	private string $cache_dir;

	public function __construct() {
		$this->cache_dir = rtrim( getenv( 'HOME' ) ?: sys_get_temp_dir(), '/' )
			. '/.wp-cli/scaffold-templates';
	}

	/**
	 * Generates project scaffolding from a template pack.
	 *
	 * Downloads (or updates) the template pack, reads its `scaffold.yml`,
	 * resolves all variables and renders each Mustache template into the
	 * target directory.
	 *
	 * ## OPTIONS
	 *
	 * <template>
	 * : Template pack reference. Accepted formats:
	 *   - `vendor/repo`                      vendor/repo on --git host (default: github)
	 *   - `vendor/repo@ref`                  pin to branch, tag or commit
	 *   - `repo`                             repo only — owner from --owner
	 *   - `https://host/vendor/repo[.git]`   full HTTPS remote URL
	 *   - `git@host:vendor/repo.git`         full SSH remote URL
	 *   - `./path` or `/abs/path`            local directory
	 *
	 * Append `:<subpath>` to any format to use a subdirectory of the repo
	 * as the pack root (for multi-pack repos):
	 *   - `vendor/repo:plugin`               use repo/plugin/ as pack root
	 *   - `vendor/repo@1.0.0:theme`          pin + subpath
	 *   - `https://host/vendor/repo:block`   full URL + subpath
	 *   - `./my-packs:plugin`                local multi-pack + subpath
	 *
	 * <slug>
	 * : Slug for the generated project (directory name, text-domain, etc.).
	 *
	 * [--git=<provider>]
	 * : Git hosting provider used when the template is a short `vendor/repo`
	 * or bare `repo` reference.
	 * ---
	 * default: github
	 * options:
	 *   - github
	 *   - gitlab
	 *   - bitbucket
	 * ---
	 *
	 * [--owner=<owner>]
	 * : Default owner (user or organisation) used when the template reference
	 * contains no slash, e.g. `wp scaffold template plugin my-plugin --owner=acme`.
	 * Best set in wp-cli.yml so you never have to type it.
	 *
	 * [--repo-pattern=<pattern>]
	 * : Pattern where `{}` is replaced by the bare repo name.
	 * `plugin` + `--repo-pattern=wp-scaffold-{}` => `wp-scaffold-plugin`.
	 * `plugin` + `--repo-pattern={}-scaffold`    => `plugin-scaffold`.
	 * `plugin` + `--repo-pattern=my-{}-tpl`      => `my-plugin-tpl`.
	 * Safe to use unquoted — `{}` is never expanded by the shell.
	 * Best set in wp-cli.yml so you never have to type it.
	 *
	 * [--dir=<dirname>]
	 * : Output directory. Defaults to the WordPress plugins directory.
	 *
	 * [--force]
	 * : Overwrite files that already exist.
	 *
	 * [--<field>=<value>]
	 * : Any parameter declared in the template pack's scaffold.yml.
	 *
	 * ## EXAMPLES
	 *
	 *     # vendor/repo — GitHub (default)
	 *     $ wp scaffold template camaleaun/wp-scaffold-plugin my-plugin
	 *
	 *     # bare repo — owner + pattern from wp-cli.yml
	 *     $ wp scaffold template plugin my-plugin
	 *
	 *     # multi-pack repo — :subpath selects which pack to use
	 *     $ wp scaffold template camaleaun/wp-scaffold-wp:plugin my-plugin
	 *     $ wp scaffold template camaleaun/wp-scaffold-wp:theme  my-theme
	 *     $ wp scaffold template camaleaun/wp-scaffold-wp:block  my-block
	 *
	 *     # pin to tag + subpath
	 *     $ wp scaffold template camaleaun/wp-scaffold-wp@1.2.0:plugin my-plugin
	 *
	 *     # full HTTPS URL
	 *     $ wp scaffold template https://github.com/camaleaun/wp-scaffold-plugin my-plugin
	 *
	 *     # SSH URL
	 *     $ wp scaffold template git@gitlab.com:acme/my-tpl.git my-thing
	 *
	 *     # GitLab provider
	 *     $ wp scaffold template acme/my-tpl my-thing --git=gitlab
	 *
	 *     # local multi-pack
	 *     $ wp scaffold template ./my-packs:plugin my-plugin
	 *
	 *     # wp-cli.yml defaults (set once, never repeat)
	 *     # scaffold template:
	 *     #   owner: camaleaun
	 *     #   git: github
	 *     #   repo-pattern: wp-scaffold-{}
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		[ $template_ref, $slug ] = $args;

		if ( in_array( $slug, [ '.', '..' ], true ) ) {
			WP_CLI::error( 'Invalid slug. Cannot be "." or "..".' );
		}

		// ── 1. Resolve template pack path ────────────────────────────────────
		$pack_path = $this->resolve_pack( $template_ref, $assoc_args );

		// ── 2. Load and validate scaffold.yml ────────────────────────────────
		$spec = $this->load_spec( $pack_path );

		// ── 3. Resolve all variables ──────────────────────────────────────────
		$vars = $this->resolve_vars( $spec, $slug, $assoc_args );

		// ── 4. Resolve output directory ───────────────────────────────────────
		$out_dir = $this->resolve_output_dir( $spec, $slug, $assoc_args );

		// ── 5. Generate files ─────────────────────────────────────────────────
		$force         = (bool) Utils\get_flag_value( $assoc_args, 'force', false );
		$files_written = $this->generate_files( $spec, $pack_path, $out_dir, $vars, $force );

		if ( empty( $files_written ) ) {
			WP_CLI::log( 'All files were skipped.' );
		} else {
			WP_CLI::success( sprintf( 'Created %d file(s) in %s', count( $files_written ), $out_dir ) );
		}

		// ── 6. Post-actions ───────────────────────────────────────────────────
		$this->run_post_actions( $spec, $slug, $assoc_args );
	}

	// ── Pack resolution ───────────────────────────────────────────────────────

	/**
	 * Returns the local path to a template pack, cloning or updating as needed.
	 *
	 * Resolution pipeline:
	 *   1. Local path     (starts with . or /)
	 *   2. Full remote URL (https:// or git@)
	 *   3. vendor/repo[@ref]  — host from --git
	 *   4. repo[@ref]         — owner from --owner, repo name optionally transformed by --repo-pattern (e.g. wp-scaffold-{})
	 *
	 * @param array<string,mixed> $assoc_args
	 */
	private function resolve_pack( string $template_ref, array $assoc_args ): string {

		// ── 0. Extract optional :subpath suffix ──────────────────────────────
		// Syntax: <ref>:<subpath> — the colon must be preceded by a non-colon char
		// and the subpath must not be empty. Applies to every ref format.
		// Examples:
		//   camaleaun/wp-scaffold-wp:plugin
		//   camaleaun/wp-scaffold-wp@1.0.0:plugin
		//   https://github.com/acme/repo@1.0.0:theme
		//   git@github.com:acme/repo.git@1.0.0:block   ← host colon is before the /
		$subpath = '';
		if ( preg_match( '#^(.*[^:]):([^:].*)$#', $template_ref, $sm ) ) {
			// Make sure this isn't the host-colon in a SSH URL (git@host:vendor/repo)
			// SSH colons always appear before the first '/', subpath colons after.
			$candidate_ref     = $sm[1];
			$candidate_subpath = $sm[2];
			$is_ssh_host_colon = str_starts_with( $template_ref, 'git@' )
				&& ! str_contains( $candidate_subpath, '/' )
				&& ! str_contains( $candidate_ref, '/' );
			if ( ! $is_ssh_host_colon ) {
				$template_ref = $candidate_ref;
				$subpath      = trim( $candidate_subpath, '/' );
			}
		}

		// ── 1. Local path ─────────────────────────────────────────────────────
		if ( str_starts_with( $template_ref, '.' ) || str_starts_with( $template_ref, '/' ) ) {
			$path = realpath( $template_ref );
			if ( ! $path || ! is_dir( $path ) ) {
				WP_CLI::error( "Local template path not found: {$template_ref}" );
			}
			return $this->apply_subpath( $path, $subpath );
		}

		// ── 2. Full remote URL (HTTPS or SSH) ─────────────────────────────────
		if ( str_starts_with( $template_ref, 'https://' ) || str_starts_with( $template_ref, 'http://' )
			|| str_starts_with( $template_ref, 'git@' ) ) {
			[ $clone_url, $ref ] = $this->split_ref_from_url( $template_ref );
			$cache_key           = $this->url_to_cache_key( $clone_url, $ref );
			$pack_path           = $this->clone_or_update( $clone_url, $ref, $cache_key, $template_ref );
			return $this->apply_subpath( $pack_path, $subpath );
		}

		// ── 3 & 4. Short reference: [vendor/]repo[@ref] ───────────────────────
		$ref     = 'HEAD';
		$raw_ref = $template_ref;

		// Split off @ref suffix (but not inside a URL — already handled above).
		if ( str_contains( $template_ref, '@' ) ) {
			$at_pos  = strrpos( $template_ref, '@' );
			$raw_ref = substr( $template_ref, 0, $at_pos );
			$ref     = substr( $template_ref, $at_pos + 1 );
		}

		// Determine vendor and repo.
		if ( str_contains( $raw_ref, '/' ) ) {
			// ── 3. vendor/repo ────────────────────────────────────────────────
			[ $vendor, $repo_name ] = explode( '/', $raw_ref, 2 );
		} else {
			// ── 4. Bare repo — apply owner + naming pattern ───────────────────
			$vendor    = Utils\get_flag_value( $assoc_args, 'owner', '' );
			$repo_name = $this->apply_repo_pattern( $raw_ref, $assoc_args );

			if ( ! $vendor ) {
				WP_CLI::error(
					"Template '{$template_ref}' has no owner. " .
					'Pass --owner=<owner> or set it in wp-cli.yml under "scaffold template:".'
				);
			}
		}

		if ( ! preg_match( '#^[A-Za-z0-9_.-]+$#', $vendor )
			|| ! preg_match( '#^[A-Za-z0-9_.-]+$#', $repo_name ) ) {
			WP_CLI::error( "Invalid template reference: {$template_ref}." );
		}

		$host      = $this->git_host( Utils\get_flag_value( $assoc_args, 'git', 'github' ) );
		$clone_url = "https://{$host}/{$vendor}/{$repo_name}.git";
		$cache_key = "{$vendor}--{$repo_name}" . ( 'HEAD' === $ref ? '' : '@' . $ref );
		$pack_path = $this->clone_or_update( $clone_url, $ref, $cache_key, "{$vendor}/{$repo_name}" );

		return $this->apply_subpath( $pack_path, $subpath );
	}

	/**
	 * Applies --repo-pattern to a bare repo name.
	 *
	 * Every `{}` in the pattern is replaced by the bare name.
	 * When no pattern is set the name is returned as-is.
	 *
	 * Uses `{}` (not `*`) to avoid shell glob expansion when the flag
	 * is passed without quotes, e.g. --repo-pattern=wp-scaffold-{}
	 *
	 * @param  array<string,mixed> $assoc_args
	 */
	private function apply_repo_pattern( string $name, array $assoc_args ): string {
		$pattern = Utils\get_flag_value( $assoc_args, 'repo-pattern', '' );
		if ( $pattern ) {
			return str_replace( '{}', $name, $pattern );
		}
		return $name;
	}

	/**
	 * Appends a subpath to a pack root, validating the result is a directory.
	 *
	 * @param string $pack_root Absolute path to the cloned/local repo root.
	 * @param string $subpath   Relative subpath extracted from the ref (may be empty).
	 */
	private function apply_subpath( string $pack_root, string $subpath ): string {
		if ( '' === $subpath ) {
			return $pack_root;
		}
		$full = $pack_root . '/' . $subpath;
		if ( ! is_dir( $full ) ) {
			WP_CLI::error( "Subpath not found in template pack: {$subpath}" );
		}
		return $full;
	}

	/**
	 * Returns the clone URL and ref from a full remote URL that may end in @ref.
	 *
	 * @return array{0: string, 1: string} [clone_url, ref]
	 */
	private function split_ref_from_url( string $url ): array {
		$ref = 'HEAD';
		// @ref suffix only when it appears after the repo path, not inside the host.
		if ( preg_match( '#^(https?://[^@]+|git@[^@]+)@([^@/]+)$#', $url, $m ) ) {
			return [ rtrim( $m[1], '.git' ) . '.git', $m[2] ];
		}
		// Normalise: ensure .git suffix.
		$clone_url = str_ends_with( $url, '.git' ) ? $url : $url . '.git';
		return [ $clone_url, $ref ];
	}

	/**
	 * Derives a filesystem-safe cache key from a clone URL and ref.
	 */
	private function url_to_cache_key( string $clone_url, string $ref ): string {
		// Strip protocol and .git, replace path separators.
		$key = preg_replace( '#^(https?://|git@)#', '', $clone_url );
		$key = str_replace( [ ':', '/', '.git' ], [ '--', '--', '' ], $key );
		if ( 'HEAD' !== $ref ) {
			$key .= '@' . $ref;
		}
		return $key;
	}

	/**
	 * Returns the hostname for a named Git provider.
	 */
	private function git_host( string $provider ): string {
		return match ( strtolower( $provider ) ) {
			'gitlab'    => 'gitlab.com',
			'bitbucket' => 'bitbucket.org',
			default     => 'github.com',   // 'github' or anything unrecognised
		};
	}

	/**
	 * Clones a repo or fetches + checks out the latest, then returns the local path.
	 */
	private function clone_or_update( string $clone_url, string $ref, string $cache_key, string $label ): string {
		$local_path = $this->cache_dir . '/' . $cache_key;

		if ( is_dir( $local_path . '/.git' ) ) {
			WP_CLI::log( "Updating template pack {$label}..." );
			$this->shell( "git -C {$local_path} fetch --quiet origin" );
			$checkout = ( 'HEAD' === $ref ) ? 'FETCH_HEAD' : escapeshellarg( $ref );
			$this->shell( "git -C {$local_path} checkout --quiet {$checkout}" );
		} else {
			WP_CLI::log( "Downloading template pack {$label}..." );
			wp_mkdir_p( $this->cache_dir );
			$depth = ( 'HEAD' === $ref ) ? '--depth=1' : '';
			$this->shell( sprintf(
				'git clone --quiet %s %s %s',
				$depth,
				escapeshellarg( $clone_url ),
				escapeshellarg( $local_path )
			) );
			if ( 'HEAD' !== $ref ) {
				$this->shell( "git -C {$local_path} checkout --quiet " . escapeshellarg( $ref ) );
			}
		}

		return $local_path;
	}

	// ── Spec loading ──────────────────────────────────────────────────────────

	/**
	 * Loads and validates scaffold.yml from the pack directory.
	 *
	 * @return array<string,mixed>
	 */
	private function load_spec( string $pack_path ): array {
		$yaml_path = $pack_path . '/scaffold.yml';

		if ( ! file_exists( $yaml_path ) ) {
			WP_CLI::error( "scaffold.yml not found in template pack at {$pack_path}" );
		}

		$spec = \Mustangostang\Spyc::YAMLLoad( $yaml_path );

		if ( empty( $spec['files'] ) ) {
			WP_CLI::error( 'scaffold.yml must define at least one file group under `files:`' );
		}

		return $spec;
	}

	// ── Variable resolution ───────────────────────────────────────────────────

	/**
	 * Resolves all template variables from CLI args, parameters and computed rules.
	 *
	 * Resolution order (later wins):
	 *   1. Parameter defaults from scaffold.yml
	 *   2. `derive` expressions (when default is null and user didn't pass the flag)
	 *   3. User-supplied CLI flags
	 *   4. `computed` expressions (always re-evaluated after all inputs are settled)
	 *
	 * @param  array<string,mixed> $spec
	 * @param  array<string,mixed> $assoc_args
	 * @return array<string,mixed>
	 */
	private function resolve_vars( array $spec, string $slug, array $assoc_args ): array {
		$vars = [ 'slug' => $slug, 'wp_version' => get_bloginfo( 'version' ) ];

		// 1. Parameter defaults.
		foreach ( $spec['parameters'] ?? [] as $name => $def ) {
			if ( isset( $def['type'] ) && 'flag' === $def['type'] ) {
				continue; // flags resolved separately in post_actions / generate_files
			}
			$vars[ $name ] = $def['default'] ?? null;
		}

		// 2. Derive expressions (only when default is null and user didn't supply the flag).
		foreach ( $spec['parameters'] ?? [] as $name => $def ) {
			if ( ! isset( $def['derive'] ) ) {
				continue;
			}
			if ( ! isset( $assoc_args[ $name ] ) && null === ( $vars[ $name ] ?? null ) ) {
				$vars[ $name ] = $this->evaluate_expr( $def['derive'], $vars );
			}
		}

		// 3. User-supplied CLI flags override defaults.
		foreach ( $spec['parameters'] ?? [] as $name => $def ) {
			if ( isset( $def['type'] ) && 'flag' === $def['type'] ) {
				continue;
			}
			if ( isset( $assoc_args[ $name ] ) ) {
				$vars[ $name ] = $assoc_args[ $name ];
			}
		}

		// 4. Computed — always evaluated last so they can reference resolved params.
		foreach ( $spec['computed'] ?? [] as $name => $expr ) {
			$vars[ $name ] = $this->evaluate_expr( $expr, $vars );
		}

		// 5. Apply explicit variable mapping from scaffold.yml (if present).
		// Variables not listed resolve by matching their own name directly.
		$mapped = [];
		foreach ( $spec['variables'] ?? [] as $placeholder => $source ) {
			$mapped[ $placeholder ] = $vars[ $source ] ?? $vars[ $placeholder ] ?? null;
		}
		// Merge: explicit mapping wins, then fall back to direct name match.
		return array_merge( $vars, $mapped );
	}

	/**
	 * Evaluates a simple expression string against a variable map.
	 *
	 * Supported functions: str_replace, strtoupper, strtolower, ucwords.
	 * Supported operator: ~ (string concatenation).
	 * Variable names are resolved from $vars.
	 *
	 * Examples:
	 *   "ucwords(slug, '-')"                        → ucwords($vars['slug'], '-')
	 *   "str_replace(' ', '\\\\', plugin_name)"     → str_replace(' ', '\\', $vars['plugin_name'])
	 *   "plugin_namespace ~ '\\\\Tests'"             → $vars['plugin_namespace'] . '\\Tests'
	 *
	 * @param  array<string,mixed> $vars
	 */
	private function evaluate_expr( string $expr, array $vars ): string {
		// Handle concatenation operator ~
		if ( str_contains( $expr, ' ~ ' ) ) {
			$parts = explode( ' ~ ', $expr );
			return implode( '', array_map( fn( $p ) => $this->evaluate_expr( trim( $p ), $vars ), $parts ) );
		}

		// Quoted string literal.
		if ( preg_match( "/^'(.*)'$/s", $expr, $m ) ) {
			return stripslashes( $m[1] );
		}

		// Known variable name.
		if ( isset( $vars[ $expr ] ) ) {
			return (string) $vars[ $expr ];
		}

		// Function call: name(arg1, arg2, ...)
		if ( preg_match( '/^(\w+)\((.+)\)$/s', $expr, $m ) ) {
			$fn   = $m[1];
			$raw  = $m[2];
			$argv = $this->parse_args( $raw, $vars );

			$allowed = [ 'str_replace', 'strtoupper', 'strtolower', 'ucwords', 'implode', 'explode', 'strpos' ];
			if ( ! in_array( $fn, $allowed, true ) ) {
				WP_CLI::error( "scaffold.yml computed: unsupported function '{$fn}'." );
			}

			return (string) $fn( ...$argv );
		}

		// Bare unquoted value (fallback).
		return $expr;
	}

	/**
	 * Parses a comma-separated argument list for evaluate_expr().
	 *
	 * Handles quoted strings and variable references.
	 *
	 * @param  array<string,mixed> $vars
	 * @return list<string>
	 */
	private function parse_args( string $raw, array $vars ): array {
		// Split on commas that are NOT inside single quotes.
		$parts = preg_split( "/,(?=(?:[^']*'[^']*')*[^']*$)/", $raw );
		$argv  = [];
		foreach ( $parts as $part ) {
			$argv[] = $this->evaluate_expr( trim( $part ), $vars );
		}
		return $argv;
	}

	// ── Output directory ──────────────────────────────────────────────────────

	private function resolve_output_dir( array $spec, string $slug, array $assoc_args ): string {
		$dir = Utils\get_flag_value( $assoc_args, 'dir', null );
		if ( $dir ) {
			if ( ! is_dir( $dir ) ) {
				WP_CLI::error( "Directory does not exist: {$dir}" );
			}
			return rtrim( $dir, '/' ) . '/' . $slug;
		}
		// Default to WP plugins dir when WordPress is loaded, tmp otherwise.
		$base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( sys_get_temp_dir() . '/scaffold-output' );
		wp_mkdir_p( $base );
		return $base . '/' . $slug;
	}

	// ── File generation ───────────────────────────────────────────────────────

	/**
	 * Renders all enabled file groups and writes them to disk.
	 *
	 * @param  array<string,mixed> $spec
	 * @param  array<string,mixed> $vars
	 * @return list<string> Paths of files actually written.
	 */
	private function generate_files( array $spec, string $pack_path, string $out_dir, array $vars, bool $force ): array {
		$templates_dir = $pack_path;
		$mustache      = new Mustache_Engine();
		$written       = [];

		foreach ( $spec['files'] as $group_name => $group ) {
			$items = $group['items'] ?? [];

			// skip_when: { flag: skip-tests }
			if ( isset( $group['skip_when']['flag'] ) ) {
				$flag = $group['skip_when']['flag'];
				if ( Utils\get_flag_value( $assoc_args, $flag, false ) ) {
					continue;
				}
			}

			// select_by: { param: ci } — only emit the matching item.
			if ( isset( $group['select_by']['param'] ) ) {
				$param    = $group['select_by']['param'];
				$selected = $vars[ $param ] ?? ( $spec['parameters'][ $param ]['default'] ?? null );
				$items    = array_filter(
					$items,
					fn( $item ) => ( $item['when'] ?? null ) === $selected
				);
			}

			foreach ( $items as $item ) {
				$dest_rel  = $mustache->render( $item['dest'], $vars );
				$dest_path = $out_dir . '/' . $dest_rel;
				$tpl_path  = $templates_dir . '/' . $item['template'];

				if ( ! file_exists( $tpl_path ) ) {
					WP_CLI::warning( "Template file not found, skipping: {$item['template']}" );
					continue;
				}

				if ( file_exists( $dest_path ) && ! $force ) {
					WP_CLI::log( "Skipped (already exists): {$dest_rel}" );
					continue;
				}

				wp_mkdir_p( dirname( $dest_path ) );

				$rendered = $mustache->render( file_get_contents( $tpl_path ), $vars );

				if ( false === file_put_contents( $dest_path, $rendered ) ) {
					WP_CLI::error( "Could not write file: {$dest_path}" );
				}

				$written[] = $dest_rel;
				WP_CLI::log( "Created: {$dest_rel}" );
			}
		}

		return $written;
	}

	// ── Post-actions ─────────────────────────────────────────────────────────

	private function run_post_actions( array $spec, string $slug, array $assoc_args ): void {
		foreach ( $spec['post_actions'] ?? [] as $action_def ) {
			$action = $action_def['action'] ?? '';

			// Evaluate `when` condition.
			if ( isset( $action_def['when']['flag'] ) ) {
				$flag = $action_def['when']['flag'];
				if ( ! Utils\get_flag_value( $assoc_args, $flag, false ) ) {
					continue;
				}
			}

			switch ( $action ) {
				case 'plugin_activate':
					WP_CLI::run_command( [ 'plugin', 'activate', $slug ] );
					break;
				case 'plugin_activate_network':
					WP_CLI::run_command( [ 'plugin', 'activate', $slug ], [ 'network' => true ] );
					break;
				case 'theme_activate':
					WP_CLI::run_command( [ 'theme', 'activate', $slug ] );
					break;
				default:
					WP_CLI::warning( "Unknown post_action: {$action}" );
			}
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private function shell( string $cmd ): void {
		$output     = [];
		$return_var = 0;
		exec( escapeshellcmd( $cmd ) . ' 2>&1', $output, $return_var );
		if ( 0 !== $return_var ) {
			WP_CLI::error( 'Command failed: ' . $cmd . "\n" . implode( "\n", $output ) );
		}
	}
}