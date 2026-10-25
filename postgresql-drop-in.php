<?php
/**
 * Plugin Name:       PostgreSQL Drop-in
 * Plugin URI:        https://wordpress.org/plugins/postgresql-drop-in/
 * Description:       PostgreSQL support for WordPress. Translates wpdb's MySQL SQL to PostgreSQL through an AST-based pipeline. Does nothing unless explicitly enabled in wp-config.php.
 * Version:           0.1.0-beta
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            trgino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postgresql-drop-in
 *
 * @package Slonik
 */

defined( 'ABSPATH' ) || exit;

define( 'WPSLONIK_VERSION', '0.1.0-beta' );
define( 'WPSLONIK_PLUGIN_FILE', __FILE__ );

// Text domain for UI strings (i18n, M8.3). Translation files live in
// languages/; __() falls back to the English string when a file is missing.
load_plugin_textdomain( 'postgresql-drop-in', false, plugin_dir_path( __FILE__ ) . '/languages' );

/*
 * M0 skeleton: activating the plugin never changes the database or installs a
 * drop-in (decision P-16). PostgreSQL mode requires an explicit
 * WPSLONIK_ENABLED === true in wp-config.php. Runtime, health checks and CLI
 * arrive in later milestones.
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$wpslonik_cli_files = array(
		'/src/Health/Result.php',
		'/src/Health/Check.php',
		'/src/Health/Probe.php',
		'/src/Health/Env.php',
		'/src/Health/Runner.php',
		'/src/Health/PdoProbe.php',
		'/src/Health/Checks/PhpCheck.php',
		'/src/Health/Checks/ConstantsCheck.php',
		'/src/Health/Checks/DropinCheck.php',
		'/src/Health/Checks/GuardCheck.php',
		'/src/Health/Checks/CompatCheck.php',
		'/src/Health/Checks/DbCheck.php',
		'/src/Chain/DropinMarker.php',
		'/src/Chain/Classifier.php',
		'/src/Chain/Installer.php',
		'/src/Sql/TranslationException.php',
		'/src/Sql/HostPort.php',
	'/src/Sql/Unsupported.php',
		'/src/Sql/PlanStep.php',
		'/src/Sql/ExecutionPlan.php',
		'/src/Sql/MysqlParser.php',
		'/src/Sql/PgQuote.php',
		'/src/Dialect/Pg/DateFormat.php',
		'/src/Dialect/Pg/Functions.php',
		'/src/Dialect/Pg/IdentScope.php',
		'/src/Dialect/Pg/MinEmitter.php',
		'/src/Dialect/Pg/Semantics.php',
		'/src/Schema/CiMode.php',
		'/src/Schema/Catalog.php',
		'/src/Schema/ColumnOverrides.php',
		'/src/Schema/MinDdl.php',
		'/src/Schema/MinInfo.php',
		'/src/Schema/MinAlter.php',
		'/src/Schema/MinShow.php',
		'/src/Schema/SpellingAudit.php',
		'/src/Compat/FileSource.php',
		'/src/Compat/ArraySource.php',
		'/src/Compat/DirSource.php',
		'/src/Compat/ParserFactory.php',
		'/src/Compat/HostParserFactory.php',
		'/src/Compat/WpdbVisitor.php',
		'/src/Compat/Collector.php',
		'/src/Compat/SqlExtractor.php',
		'/src/Compat/RuleTags.php',
		'/src/Compat/ScanReport.php',
		'/src/Compat/Scanner.php',
		'/src/Schema/SchemaChecker.php',
		'/src/Schema/SequenceSync.php',
		'/src/Runtime/ResultShaper.php',
		'/src/Runtime/ErrorMapper.php',
		'/src/Runtime/WpslonikDb.php',
		'/src/Sql/MinTranslator.php',
		'/src/Cli/HealthCommand.php',
		'/src/Cli/CompatScanCommand.php',
		'/src/Cli/CompatKnownCommand.php',
		'/src/Compat/KnownList.php',
		'/src/Cli/ManageCommand.php',
		'/src/Cli/DbGuard.php',
		'/src/Cli/CompatCommand.php',
		'/src/Cli/DbTools.php',
		'/src/Cli/InspectCommand.php',
		'/src/Migrate/MigrateConfig.php',
		'/src/Migrate/RowTransform.php',
		'/src/Migrate/SkipRowSignal.php',
		'/src/Migrate/MigrateDataError.php',
		'/src/Migrate/CopySource.php',
		'/src/Migrate/MySqlSource.php',
		'/src/Migrate/SchemaStage.php',
		'/src/Migrate/CopyBatch.php',
		'/src/Migrate/PgTarget.php',
		'/src/Migrate/QueryPlanner.php',
		'/src/Migrate/StateStore.php',
		'/src/Migrate/State.php',
		'/src/Migrate/TableCopier.php',
		'/src/Migrate/Verifier.php',
		'/src/Cli/MigrateCommand.php',
		'/src/Migrate/Plan.php',
		'/src/Migrate/Report.php',
		'/src/Migrate/Runner.php',
		'/src/Audit/Redactor.php',
		'/src/Audit/CompatLog.php',
		'/src/Audit/SourceGuesser.php',
		'/src/Audit/LogEntry.php',
		'/src/Cache/CacheInterface.php',
		'/src/Cache/ArrayCache.php',
		'/src/Cache/ApcuCache.php',
		'/src/Cache/FileCache.php',
		'/src/Cache/CacheFactory.php',
		'/src/Admin/Page.php',
	);
	foreach ( $wpslonik_cli_files as $wpslonik_cli_file ) {
		require_once __DIR__ . $wpslonik_cli_file;
	}
	$wpslonik_cli_commands = array(
		array( 'slonik health', array( 'Slonik\Cli\HealthCommand', 'health' ) ),
		array( 'slonik status', array( 'Slonik\Cli\HealthCommand', 'status' ) ),
		array( 'slonik install-dropin', array( 'Slonik\Cli\ManageCommand', 'installDropin' ) ),
		array( 'slonik uninstall-dropin', array( 'Slonik\Cli\ManageCommand', 'uninstallDropin' ) ),
		array( 'slonik chain', array( 'Slonik\Cli\ManageCommand', 'chain' ) ),
		array( 'slonik compat-log', array( 'Slonik\Cli\CompatCommand', 'compatLog' ) ),
		array( 'slonik compat', array( 'Slonik\Cli\CompatScanCommand', 'run' ) ),
		array( 'slonik known', array( 'Slonik\Cli\CompatKnownCommand', 'run' ) ),
		array( 'slonik dump', array( 'Slonik\Cli\DbTools', 'dump' ) ),
		array( 'slonik restore', array( 'Slonik\Cli\DbTools', 'restore' ) ),
		array( 'slonik migrate', array( 'Slonik\Cli\MigrateCommand', 'entry' ) ),
		array( 'slonik translate', array( 'Slonik\Cli\InspectCommand', 'translateEntry' ) ),
		array( 'slonik schema:check', array( 'Slonik\Cli\InspectCommand', 'schemaCheck' ) ),
	);
	foreach ( $wpslonik_cli_commands as $wpslonik_cli_command ) {
		WP_CLI::add_command( $wpslonik_cli_command[0], $wpslonik_cli_command[1] );
	}
}

if ( function_exists( 'add_action' ) ) {
	require_once __DIR__ . '/src/Admin/Page.php';
	add_action( 'admin_menu', array( 'Slonik\Admin\Page', 'register' ) );
}
