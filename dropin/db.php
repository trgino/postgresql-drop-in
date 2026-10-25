<?php
/**
 * WP-Slonik drop-in loader template (M2a thin slice).
 *
 * Installed as wp-content/db.php. Transparent unless WPSLONIK_ENABLED === true (P-16).
 * Version handshake, chaining and wp-cli guard land in M4.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once ABSPATH . WPINC . '/class-wpdb.php';

$wpslonik_dir = defined( 'WPSLONIK_PLUGIN_DIR' )
	? WPSLONIK_PLUGIN_DIR
	: WP_CONTENT_DIR . '/plugins/postgresql-drop-in';

if ( ! defined( 'WPSLONIK_ENABLED' ) || true !== WPSLONIK_ENABLED ) {
	return;
}

foreach ( array(
	$wpslonik_dir . '/src/Sql/TranslationException.php',
	$wpslonik_dir . '/src/Sql/HostPort.php',
	$wpslonik_dir . '/src/Sql/Unsupported.php',
	$wpslonik_dir . '/src/Sql/PlanStep.php',
	$wpslonik_dir . '/src/Sql/ExecutionPlan.php',
	$wpslonik_dir . '/src/Sql/MysqlParser.php',
	$wpslonik_dir . '/src/Sql/PgQuote.php',
	$wpslonik_dir . '/src/Sql/MinTranslator.php',
	$wpslonik_dir . '/src/Dialect/Pg/DateFormat.php',
	$wpslonik_dir . '/src/Dialect/Pg/Functions.php',
	$wpslonik_dir . '/src/Dialect/Pg/IdentScope.php',
	$wpslonik_dir . '/src/Dialect/Pg/MinEmitter.php',
	$wpslonik_dir . '/src/Dialect/Pg/Semantics.php',
	$wpslonik_dir . '/src/Schema/CiMode.php',
	$wpslonik_dir . '/src/Schema/Catalog.php',
	$wpslonik_dir . '/src/Schema/ColumnOverrides.php',
	$wpslonik_dir . '/src/Schema/MinDdl.php',
	$wpslonik_dir . '/src/Schema/MinInfo.php',
	$wpslonik_dir . '/src/Schema/MinAlter.php',
	$wpslonik_dir . '/src/Schema/MinShow.php',
	$wpslonik_dir . '/src/Schema/SequenceSync.php',
	$wpslonik_dir . '/src/Schema/SpellingAudit.php',
	$wpslonik_dir . '/src/Health/Result.php',
	$wpslonik_dir . '/src/Health/Check.php',
	$wpslonik_dir . '/src/Health/Probe.php',
	$wpslonik_dir . '/src/Health/Env.php',
	$wpslonik_dir . '/src/Health/Runner.php',
	$wpslonik_dir . '/src/Health/PdoProbe.php',
	$wpslonik_dir . '/src/Health/Checks/PhpCheck.php',
	$wpslonik_dir . '/src/Health/Checks/ConstantsCheck.php',
	$wpslonik_dir . '/src/Health/Checks/DropinCheck.php',
	$wpslonik_dir . '/src/Health/Checks/GuardCheck.php',
	$wpslonik_dir . '/src/Health/Checks/CompatCheck.php',
	$wpslonik_dir . '/src/Health/Checks/DbCheck.php',
	$wpslonik_dir . '/src/Chain/DropinMarker.php',
	$wpslonik_dir . '/src/Chain/Classifier.php',
	$wpslonik_dir . '/src/Chain/Installer.php',
	$wpslonik_dir . '/src/Audit/Redactor.php',
	$wpslonik_dir . '/src/Audit/CompatLog.php',
	$wpslonik_dir . '/src/Audit/SourceGuesser.php',
	$wpslonik_dir . '/src/Audit/LogEntry.php',
	$wpslonik_dir . '/src/Cache/CacheInterface.php',
	$wpslonik_dir . '/src/Cache/ArrayCache.php',
	$wpslonik_dir . '/src/Cache/ApcuCache.php',
	$wpslonik_dir . '/src/Cache/FileCache.php',
	$wpslonik_dir . '/src/Cache/CacheFactory.php',
	$wpslonik_dir . '/src/Admin/Page.php',
	$wpslonik_dir . '/src/Cli/HealthCommand.php',
	$wpslonik_dir . '/src/Cli/CompatScanCommand.php',
	$wpslonik_dir . '/src/Cli/CompatKnownCommand.php',
	$wpslonik_dir . '/src/Compat/KnownList.php',
	$wpslonik_dir . '/src/Cli/ManageCommand.php',
	$wpslonik_dir . '/src/Cli/DbGuard.php',
	$wpslonik_dir . '/src/Cli/CompatCommand.php',
	$wpslonik_dir . '/src/Cli/DbTools.php',
	$wpslonik_dir . '/src/Cli/InspectCommand.php',
	$wpslonik_dir . '/src/Migrate/MigrateConfig.php',
	$wpslonik_dir . '/src/Migrate/RowTransform.php',
	$wpslonik_dir . '/src/Migrate/SkipRowSignal.php',
	$wpslonik_dir . '/src/Migrate/MigrateDataError.php',
	$wpslonik_dir . '/src/Migrate/CopySource.php',
	$wpslonik_dir . '/src/Migrate/MySqlSource.php',
	$wpslonik_dir . '/src/Migrate/SchemaStage.php',
	$wpslonik_dir . '/src/Migrate/CopyBatch.php',
	$wpslonik_dir . '/src/Migrate/PgTarget.php',
	$wpslonik_dir . '/src/Migrate/QueryPlanner.php',
	$wpslonik_dir . '/src/Migrate/StateStore.php',
	$wpslonik_dir . '/src/Migrate/State.php',
	$wpslonik_dir . '/src/Migrate/TableCopier.php',
	$wpslonik_dir . '/src/Migrate/Verifier.php',
	$wpslonik_dir . '/src/Cli/MigrateCommand.php',
	$wpslonik_dir . '/src/Migrate/Plan.php',
	$wpslonik_dir . '/src/Migrate/Report.php',
	$wpslonik_dir . '/src/Migrate/Runner.php',
	$wpslonik_dir . '/src/Compat/FileSource.php',
	$wpslonik_dir . '/src/Compat/ArraySource.php',
	$wpslonik_dir . '/src/Compat/DirSource.php',
	$wpslonik_dir . '/src/Compat/ParserFactory.php',
	$wpslonik_dir . '/src/Compat/HostParserFactory.php',
	$wpslonik_dir . '/src/Compat/WpdbVisitor.php',
	$wpslonik_dir . '/src/Compat/Collector.php',
	$wpslonik_dir . '/src/Compat/SqlExtractor.php',
	$wpslonik_dir . '/src/Compat/RuleTags.php',
	$wpslonik_dir . '/src/Compat/ScanReport.php',
	$wpslonik_dir . '/src/Compat/Scanner.php',
	$wpslonik_dir . '/src/Schema/SchemaChecker.php',
	$wpslonik_dir . '/src/Runtime/ResultShaper.php',
	$wpslonik_dir . '/src/Runtime/ErrorMapper.php',
	$wpslonik_dir . '/src/Runtime/WpslonikDb.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/parser/class-wp-parser-token.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/parser/class-wp-parser-node.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/parser/class-wp-parser-grammar.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/parser/class-wp-parser.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/class-wp-mysql-token.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/class-wp-mysql-lexer.php',
	$wpslonik_dir . '/third-party/mysql-parser/src/class-wp-mysql-parser-factory.php',
) as $wpslonik_file ) {
	if ( ! is_file( $wpslonik_file ) ) {
		header( 'HTTP/1.1 500 Internal Server Error' );
		echo 'WP-Slonik is enabled but its files are missing. Re-install the slonik plugin or remove wp-content/db.php.';
		exit( 1 );
	}
	require_once $wpslonik_file;
}

$wpslonik_chained_class = null;
$wpslonik_installer     = new \Slonik\Chain\Installer( WP_CONTENT_DIR, '' );
$wpslonik_compiled      = $wpslonik_installer->compiledFile();
if ( null !== $wpslonik_compiled ) {
	// The backup keeps a non-executable extension on purpose: it must never
	// run from the web root (audit D-17).
	$wpslonik_backup_code = file_get_contents( WP_CONTENT_DIR . '/wpslonik/chained/db.php.bak' );
	if ( is_string( $wpslonik_backup_code ) ) {
		$wpslonik_chained_class = \Slonik\Chain\Classifier::className( $wpslonik_backup_code );
	}
}
if ( null !== $wpslonik_chained_class && '' !== $wpslonik_chained_class ) {
	require_once $wpslonik_compiled;
}
if ( null !== $wpslonik_chained_class && '' !== $wpslonik_chained_class && class_exists( $wpslonik_chained_class, false ) ) {
	try {
		$GLOBALS['wpdb'] = new $wpslonik_chained_class( WPSLONIK_DB_USER, WPSLONIK_DB_PASSWORD, WPSLONIK_DB_NAME, WPSLONIK_DB_HOST );
	} catch ( \Throwable $wpslonik_chain_error ) {
		$GLOBALS['wpdb'] = new WPSLONIK_DB( WPSLONIK_DB_USER, WPSLONIK_DB_PASSWORD, WPSLONIK_DB_NAME, WPSLONIK_DB_HOST );
	}
} else {
	$wpslonik_log_dir = WP_CONTENT_DIR . '/wpslonik';
	if ( ! is_dir( $wpslonik_log_dir ) && function_exists( 'wp_mkdir_p' ) ) {
		@wp_mkdir_p( $wpslonik_log_dir );
	}
	// Guard the artifact directory (no listing, no direct access).
	\Slonik\Chain\Installer::guardDir( $wpslonik_log_dir );
	$GLOBALS['wpdb'] = new WPSLONIK_DB(
		WPSLONIK_DB_USER,
		WPSLONIK_DB_PASSWORD,
		WPSLONIK_DB_NAME,
		WPSLONIK_DB_HOST,
		$wpslonik_log_dir . '/compat-log.jsonl',
		defined( 'WPSLONIK_COMPAT_SLOW_MS' ) ? (string) WPSLONIK_COMPAT_SLOW_MS : null
	);
}
