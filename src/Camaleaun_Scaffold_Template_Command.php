<?php

use WP_CLI\Utils;

/**
 * Generates project scaffolding from a remote template pack.
 *
 * A template pack is a GitHub repository containing:
 * - `scaffold.yml`  — declares parameters, computed values, variable mapping and files
 * - `templates/`    — Mustache template files
 *
 * Both Mustache (Mustache_Engine) and YAML (Mustangostang\Spyc) are bundled
 * inside wp-cli.phar — no extra Composer dependencies required in this package.
 *
 * ## EXAMPLES
 *
 *     $ wp scaffold template camaleaun/wp-scaffold-plugin my-plugin
 *     $ wp scaffold template camaleaun/wp-scaffold-plugin my-plugin --plugin_author="Jane"
 *     $ wp scaffold template camaleaun/wp-scaffold-theme my-theme --activate
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
	 * Downloads (or updates) the template pack from GitHub, reads its
	 * `scaffold.yml`, resolves all variables and renders each Mustache
	 * template into the target directory.
	 *
	 * ## OPTIONS
	 *
	 * <template>
	 * : GitHub repository of the template pack in `vendor/repo` format.
	 * Use `vendor/repo@ref` to pin a branch, tag or commit.
	 *
	 * <slug>
	 * : The slug for the generated project (directory name, text-domain, etc.).
	 *
	 * [--dir=<dirname>]
	 * : Output directory. Defaults to the WordPress plugins directory.
	 *
	 * [--force]
	 * : Overwrite files that already exist.
	 *
	 * [--<field>=<value>]
	 * : Any parameter declared in the template pack's scaffold.yml.
	 * Run `wp scaffold template <template> --help` to list them.
	 *
	 * ## EXAMPLES
	 *
	 *     # Scaffold a plugin using the default camaleaun plugin template
	 *     $ wp scaffold template camaleaun/wp-scaffold-plugin my-plugin
	 *
	 *     # Pass template parameters
	 *     $ wp scaffold template camaleaun/wp-scaffold-plugin my-plugin \
	 *         --plugin_name="My Plugin" \
	 *         --plugin_author="Jane Doe" \
	 *         --activate
	 *
	 *     # Pin to a specific tag
	 *     $ wp scaffold template camaleaun/wp-scaffold-plugin@1.2.0 my-plugin
	 *
	 *     # Use a local template pack (path starting with ./ or /)
	 *     $ wp scaffold template ./path/to/my-template my-thing
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		[ $template_ref, $slug ] = $args;

		if ( in_array( $slug, [ '.', '..' ], true ) ) {
			WP_CLI::error( 'Invalid slug. Cannot be "." or "..".' );
		}

		// ── 1. Resolve template pack path ────────────────────────────────────
		$pack_path = $this->resolve_pack( $template_ref );

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
	 * Accepts:
	 *   - `vendor/repo`        — latest main/master from GitHub
	 *   - `vendor/repo@ref`    — specific branch, tag or commit
	 *   - `./local/path`       — local directory, used as-is
	 *   - `/absolute/path`     — local directory, used as-is
	 */
	private function resolve_pack( string $template_ref ): string {
		// Local path.
		if ( str_starts_with( $template_ref, '.' ) || str_starts_with( $template_ref, '/' ) ) {
			$path = realpath( $template_ref );
			if ( ! $path || ! is_dir( $path ) ) {
				WP_CLI::error( "Local template path not found: {$template_ref}" );
			}
			return $path;
		}

		// GitHub: vendor/repo[@ref]
		$ref  = 'HEAD';
		$repo = $template_ref;
		if ( str_contains( $template_ref, '@' ) ) {
			[ $repo, $ref ] = explode( '@', $template_ref, 2 );
		}

		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			WP_CLI::error( "Invalid template reference: {$template_ref}. Use vendor/repo or vendor/repo@ref." );
		}

		$cache_key  = str_replace( '/', '--', $repo ) . ( 'HEAD' === $ref ? '' : '@' . $ref );
		$local_path = $this->cache_dir . '/' . $cache_key;

		if ( is_dir( $local_path . '/.git' ) ) {
			WP_CLI::log( "Updating template pack {$template_ref}..." );
			$this->shell( "git -C {$local_path} fetch --quiet origin" );
			$checkout = ( 'HEAD' === $ref ) ? 'FETCH_HEAD' : $ref;
			$this->shell( "git -C {$local_path} checkout --quiet {$checkout}" );
		} else {
			WP_CLI::log( "Downloading template pack {$template_ref}..." );
			wp_mkdir_p( $this->cache_dir );
			$clone_url = "https://github.com/{$repo}.git";
			$depth     = ( 'HEAD' === $ref ) ? '--depth=1' : '';
			$this->shell( "git clone --quiet {$depth} {$clone_url} {$local_path}" );
			if ( 'HEAD' !== $ref ) {
				$this->shell( "git -C {$local_path} checkout --quiet {$ref}" );
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
		$templates_dir = $pack_path . '/templates';
		$mustache      = new Mustache_Engine();
		$written       = [];

		foreach ( $spec['files'] as $group_name => $group ) {
			$items = $group['items'] ?? [];

			// skip_when: { flag: skip-tests }
			if ( isset( $group['skip_when']['flag'] ) ) {
				$flag = $group['skip_when']['flag'];
				if ( Utils\get_flag_value( $GLOBALS['assoc_args'] ?? [], $flag, false ) ) {
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