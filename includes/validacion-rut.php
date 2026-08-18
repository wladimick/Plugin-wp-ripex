<?php
/**
 * Validación de RUT chileno.
 */

if (!defined('ABSPATH')) exit;

final class RIPEX_Portal_Validacion_Rut {
  private static $instance = null;
  const META_KEY = 'afreg_additional_42210';

  public static function instance() {
    if (self::$instance === null) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    add_filter('woocommerce_registration_errors', [$this, 'validate_woocommerce_registration'], 10, 3);
    add_filter('registration_errors', [$this, 'validate_wp_registration'], 10, 3);
    add_action('user_profile_update_errors', [$this, 'validate_admin_user_edit'], 10, 3);

    add_action('woocommerce_created_customer', [$this, 'normalize_customer_meta'], 20, 1);
    add_action('personal_options_update', [$this, 'normalize_customer_meta'], 20, 1);
    add_action('edit_user_profile_update', [$this, 'normalize_customer_meta'], 20, 1);
  }

  public static function clean($rut) {
    $rut = is_string($rut) ? $rut : '';
    $rut = strtoupper(trim($rut));
    $rut = str_replace(['.', ' ', '‐', '‑', '‒', '–', '—'], ['', '', '-', '-', '-', '-', '-'], $rut);
    $rut = preg_replace('/[^0-9K\-]/', '', $rut);
    return $rut;
  }

  public static function is_valid($rut) {
    $rut = self::clean($rut);
    $rut = str_replace('-', '', $rut);

    if (strlen($rut) < 2) return false;

    $dv = substr($rut, -1);
    $num = substr($rut, 0, -1);

    if (!ctype_digit($num)) return false;

    $sum = 0;
    $factor = 2;

    for ($i = strlen($num) - 1; $i >= 0; $i--) {
      $sum += (int) $num[$i] * $factor;
      $factor++;
      if ($factor > 7) $factor = 2;
    }

    $expected = 11 - ($sum % 11);
    if ($expected === 11) $expected = '0';
    elseif ($expected === 10) $expected = 'K';
    else $expected = (string) $expected;

    return strtoupper($dv) === strtoupper((string) $expected);
  }

  public static function format($rut) {
    $rut = self::clean($rut);
    $rut = str_replace('-', '', $rut);

    if (strlen($rut) < 2) return $rut;

    $dv = substr($rut, -1);
    $num = substr($rut, 0, -1);

    $formatted = '';
    while (strlen($num) > 3) {
      $formatted = '.' . substr($num, -3) . $formatted;
      $num = substr($num, 0, -3);
    }
    $formatted = $num . $formatted . '-' . strtoupper($dv);

    return $formatted;
  }

  private function posted_rut() {
    if (isset($_POST[self::META_KEY])) {
      return sanitize_text_field(wp_unslash($_POST[self::META_KEY]));
    }

    return '';
  }

  public function validate_woocommerce_registration($errors, $username, $email) {
    $rut = $this->posted_rut();
    if ($rut !== '' && !self::is_valid($rut)) {
      $errors->add('ripex_invalid_rut', __('RUT inválido. Revisa el número y el dígito verificador.', 'ripex-portal'));
    }
    return $errors;
  }

  public function validate_wp_registration($errors, $sanitized_user_login, $user_email) {
    $rut = $this->posted_rut();
    if ($rut !== '' && !self::is_valid($rut)) {
      $errors->add('ripex_invalid_rut', __('RUT inválido. Revisa el número y el dígito verificador.', 'ripex-portal'));
    }
    return $errors;
  }

  public function validate_admin_user_edit($errors, $update, $user) {
    if (!isset($_POST[self::META_KEY])) return;

    $rut = $this->posted_rut();
    if ($rut !== '' && !self::is_valid($rut)) {
      $errors->add('ripex_invalid_rut', __('RUT inválido. Revisa el número y el dígito verificador.', 'ripex-portal'));
    }
  }

  public function normalize_customer_meta($user_id) {
    if (!isset($_POST[self::META_KEY])) return;

    $rut = $this->posted_rut();
    if ($rut !== '' && self::is_valid($rut)) {
      update_user_meta($user_id, self::META_KEY, self::format($rut));
    }
  }
}
