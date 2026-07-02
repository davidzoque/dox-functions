<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight i18n: switches between English (default) and Spanish
 * based on WordPress locale. No .mo files needed.
 */
class Dox_Functions_I18n {

	private static $strings = null;

	public static function is_spanish() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return strpos( $locale, 'es' ) === 0;
	}

	public static function t( $key ) {
		if ( null === self::$strings ) {
			self::$strings = self::is_spanish() ? self::es() : self::en();
		}
		return self::$strings[ $key ] ?? $key;
	}

	private static function en() {
		return array(
			'plugin_name'          => 'Dox Functions',
			'pill'                 => 'FUNCTIONS',
			'new_function'         => '+ New function',
			'safe_mode_off'        => 'Disable Safe Mode',
			'safe_mode_active'     => 'Safe mode is active: no snippets are running.',
			'safe_mode_global'     => 'Dox Functions safe mode is active: no snippets are running.',
			'my_functions'         => 'My functions',
			'list_intro'           => 'Enable or disable your PHP snippets. If a snippet throws an error, it will be auto-disabled.',
			'empty_state'          => 'You have no functions yet. Create your first one to get started.',
			'status'               => 'Status',
			'title'                => 'Title',
			'scope'                => 'Scope',
			'priority'             => 'Priority',
			'priority_hint'        => 'Lower runs first.',
			'updated'              => 'Updated',
			'edit'                 => 'Edit',
			'duplicate'            => 'Duplicate',
			'copy_suffix'          => ' (copy)',
			'delete'               => 'Delete',
			'no_title'             => '(no title)',
			'confirm_delete'       => 'Delete this snippet?',
			'back'                 => '← Back to my functions',
			'saved'                => 'Changes saved successfully.',
			'not_found'            => 'That function no longer exists.',
			'edit_function'        => 'Edit function',
			'create_function'      => 'New function',
			'editor_intro'         => 'Write your PHP code. If it fails, it will be disabled automatically.',
			'active'               => 'Active',
			'placeholder_title'    => 'E.g. Hide admin bar from editors',
			'scope_everywhere'     => 'Site-wide',
			'scope_admin'          => 'Admin only',
			'scope_frontend'       => 'Frontend only',
			'scope_once'           => 'Run once',
			'description'          => 'Description',
			'placeholder_desc'     => 'What does this function do?',
			'php_code'             => 'PHP code',
			'no_open_tag'          => 'Do not include the opening <code>&lt;?php</code> tag.',
			'cancel'               => 'Cancel',
			'save_changes'         => 'Save changes',
			'validate'             => 'Check syntax',
			'validating'           => 'Checking…',
			'syntax_ok'            => 'Syntax OK.',
			'request_error'        => 'Request failed. Try again.',
			'unauthorized'         => 'Unauthorized',
			'syntax_error'         => 'Syntax error: %s',
			'auto_disabled'        => 'Auto-disabled by an error',
			'auto_disabled_notice' => 'Dox Functions: the snippet “%1$s” was auto-disabled after an error: %2$s',
			'review_snippet'       => 'Review snippet',
			'export_json'          => 'Export JSON',
			'import_json'          => 'Import JSON',
			'import_note'          => 'Imported functions arrive deactivated.',
			'imported_ok'          => '%d functions imported (deactivated).',
			'import_error'         => 'Could not import: invalid or empty file.',
			'starter_code'         => "// Your PHP code here. Do not include the opening tag.\n",
		);
	}

	private static function es() {
		return array(
			'plugin_name'          => 'Dox Functions',
			'pill'                 => 'FUNCTIONS',
			'new_function'         => '+ Nueva función',
			'safe_mode_off'        => 'Desactivar Modo Seguro',
			'safe_mode_active'     => 'Modo seguro activo: ningún snippet se está ejecutando.',
			'safe_mode_global'     => 'Modo seguro de Dox Functions activo: ningún snippet se está ejecutando.',
			'my_functions'         => 'Mis funciones',
			'list_intro'           => 'Activa o desactiva tus snippets de PHP. Si un snippet provoca un error, se desactivará automáticamente.',
			'empty_state'          => 'Aún no tienes funciones. Crea la primera para empezar.',
			'status'               => 'Estado',
			'title'                => 'Título',
			'scope'                => 'Ámbito',
			'priority'             => 'Prioridad',
			'priority_hint'        => 'Menor = se ejecuta antes.',
			'updated'              => 'Actualizado',
			'edit'                 => 'Editar',
			'duplicate'            => 'Duplicar',
			'copy_suffix'          => ' (copia)',
			'delete'               => 'Eliminar',
			'no_title'             => '(sin título)',
			'confirm_delete'       => '¿Eliminar este snippet?',
			'back'                 => '← Volver a mis funciones',
			'saved'                => 'Cambios guardados correctamente.',
			'not_found'            => 'Esa función ya no existe.',
			'edit_function'        => 'Editar función',
			'create_function'      => 'Nueva función',
			'editor_intro'         => 'Escribe tu código PHP. Si falla, se desactivará automáticamente.',
			'active'               => 'Activa',
			'placeholder_title'    => 'Ej. Ocultar barra de admin a editores',
			'scope_everywhere'     => 'En todo el sitio',
			'scope_admin'          => 'Solo admin',
			'scope_frontend'       => 'Solo frontend',
			'scope_once'           => 'Ejecutar una vez',
			'description'          => 'Descripción',
			'placeholder_desc'     => '¿Qué hace esta función?',
			'php_code'             => 'Código PHP',
			'no_open_tag'          => 'No incluyas la etiqueta de apertura <code>&lt;?php</code>.',
			'cancel'               => 'Cancelar',
			'save_changes'         => 'Guardar cambios',
			'validate'             => 'Validar sintaxis',
			'validating'           => 'Validando…',
			'syntax_ok'            => 'Sintaxis correcta.',
			'request_error'        => 'La petición falló. Inténtalo de nuevo.',
			'unauthorized'         => 'No autorizado',
			'syntax_error'         => 'Error de sintaxis: %s',
			'auto_disabled'        => 'Desactivada automáticamente por un error',
			'auto_disabled_notice' => 'Dox Functions: el snippet «%1$s» se desactivó automáticamente por un error: %2$s',
			'review_snippet'       => 'Revisar snippet',
			'export_json'          => 'Exportar JSON',
			'import_json'          => 'Importar JSON',
			'import_note'          => 'Las funciones importadas llegan desactivadas.',
			'imported_ok'          => '%d funciones importadas (desactivadas).',
			'import_error'         => 'No se pudo importar: archivo inválido o vacío.',
			'starter_code'         => "// Tu código PHP aquí. No incluyas la etiqueta de apertura.\n",
		);
	}
}

function dox_t( $key ) {
	return Dox_Functions_I18n::t( $key );
}
