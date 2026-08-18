<?php
/**
 * Validación de ciudad/comuna chilena.
 */

if (!defined('ABSPATH')) exit;

final class RIPEX_Portal_Validacion_Ciudad {
  private static $instance = null;
  const META_KEY = 'billing_city';

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

  public static function cities() {
    return [
      'Algarrobo','Alhué','Alto Biobío','Alto del Carmen','Alto Hospicio','Ancud','Andacollo','Angol','Antofagasta','Antuco','Arauco','Arica',
      'Buin','Bulnes',
      'Cabildo','Cabo de Hornos','Cabrero','Calama','Calbuco','Caldera','Calera','Calera de Tango','Calle Larga','Camarones','Camiña','Canela','Carahue','Cartagena','Casablanca','Castro','Catemu','Cauquenes','Cañete','Cerrillos','Cerro Navia','Chaitén','Chanco','Chañaral','Chépica','Chiguayante','Chile Chico','Chillán','Chillán Viejo','Chimbarongo','Cholchol','Chonchi','Cisnes','Cobquecura','Cochamó','Cochrane','Codegua','Coelemu','Coihueco','Coinco','Colbún','Colchane','Colina','Collipulli','Coltauco','Combarbalá','Concepción','Conchalí','Concón','Constitución','Contulmo','Copiapó','Coquimbo','Coronel','Corral','Coyhaique','Cunco','Curacautín','Curacaví','Curaco de Vélez','Curanilahue','Curarrehue','Curepto','Curicó',
      'Dalcahue','Diego de Almagro','Doñihue',
      'El Bosque','El Carmen','El Monte','El Quisco','El Tabo','Empedrado','Ercilla','Estación Central',
      'Florida','Freire','Freirina','Fresia','Frutillar','Futaleufú','Futrono',
      'Galvarino','General Lagos','Gorbea','Graneros','Guaitecas',
      'Hijuelas','Hualaihué','Hualañé','Hualpén','Hualqui','Huara','Huasco','Huechuraba',
      'Illapel','Independencia','Iquique','Isla de Maipo','Isla de Pascua',
      'Juan Fernández',
      'La Cisterna','La Cruz','La Estrella','La Florida','La Granja','La Higuera','La Ligua','La Pintana','La Reina','La Serena','La Unión','Lago Ranco','Lago Verde','Laguna Blanca','Laja','Lampa','Lanco','Las Cabras','Las Condes','Lautaro','Lebu','Licantén','Limache','Linares','Litueche','Llaillay','Llanquihue','Lo Barnechea','Lo Espejo','Lo Prado','Lolol','Loncoche','Longaví','Lonquimay','Los Andes','Los Ángeles','Los Lagos','Los Muermos','Los Sauces','Los Vilos','Lota','Lumaco',
      'Machalí','Macul','Máfil','Maipú','Malloa','Marchigüe','María Elena','María Pinto','Mariquina','Maule','Maullín','Mejillones','Melipeuco','Melipilla','Molina','Monte Patria','Mostazal','Mulchén','Nacimiento','Nancagua','Natales','Navidad','Negrete','Ninhue','Nogales','Nueva Imperial','Ñiquén','Ñuñoa',
      'O’Higgins','Olivar','Ollagüe','Olmué','Osorno','Ovalle',
      'Padre Hurtado','Padre Las Casas','Paiguano','Paillaco','Paine','Palena','Palmilla','Panguipulli','Panquehue','Papudo','Paredones','Parral','Pedro Aguirre Cerda','Pelarco','Pelluhue','Pemuco','Pencahue','Penco','Peñaflor','Peñalolén','Peralillo','Perquenco','Petorca','Peumo','Pica','Pichidegua','Pichilemu','Pinto','Pirque','Pitrufquén','Placilla','Portezuelo','Porvenir','Pozo Almonte','Primavera','Providencia','Puchuncaví','Pucón','Pudahuel','Puente Alto','Puerto Montt','Puerto Octay','Puerto Varas','Pumanque','Punitaqui','Punta Arenas','Puqueldón','Purén','Purranque','Putaendo','Putre','Puyehue',
      'Queilén','Quellón','Quemchi','Quilaco','Quilicura','Quilleco','Quillón','Quillota','Quilpué','Quinchao','Quinta de Tilcoco','Quinta Normal','Quintero','Quirihue',
      'Rancagua','Ránquil','Rauco','Recoleta','Renaico','Renca','Rengo','Requínoa','Retiro','Rinconada','Río Bueno','Río Claro','Río Hurtado','Río Ibáñez','Río Negro','Río Verde','Romeral',
      'Saavedra','Sagrada Familia','Salamanca','San Antonio','San Bernardo','San Carlos','San Clemente','San Esteban','San Fabián','San Felipe','San Fernando','San Gregorio','San Ignacio','San Javier','San Joaquín','San José de Maipo','San Juan de la Costa','San Miguel','San Nicolás','San Pablo','San Pedro','San Pedro de Atacama','San Pedro de la Paz','San Rafael','San Ramón','San Rosendo','San Vicente','Santa Bárbara','Santa Cruz','Santa Juana','Santa María','Santiago','Santo Domingo','Sierra Gorda','Talagante','Talca','Talcahuano','Taltal','Temuco','Teno','Teodoro Schmidt','Tierra Amarilla','Tiltil','Timaukel','Tirúa','Tocopilla','Toltén','Tomé','Torres del Paine','Tortel','Traiguén','Treguaco','Tucapel',
      'Valdivia','Vallenar','Valparaíso','Vichuquén','Victoria','Vicuña','Vilcún','Villa Alemana','Villarrica','Viña del Mar','Vitacura',
      'Yerbas Buenas','Yumbel','Yungay',
      'Zapallar'
    ];
  }

  public static function normalize($value) {
    $value = is_string($value) ? trim(wp_strip_all_tags($value)) : '';
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
  }

  public static function normalize_key($value) {
    $value = self::normalize($value);
    $value = remove_accents($value);
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]/', '', $value);
  }

  public static function canonical($value) {
    $key = self::normalize_key($value);
    if ($key === '') return '';

    foreach (self::cities() as $city) {
      if (self::normalize_key($city) === $key) return $city;
    }

    return '';
  }

  public static function is_valid($value) {
    return self::canonical($value) !== '';
  }

  private function posted_city() {
    if (isset($_POST[self::META_KEY])) {
      return sanitize_text_field(wp_unslash($_POST[self::META_KEY]));
    }

    return '';
  }

  public function validate_woocommerce_registration($errors, $username, $email) {
    $city = $this->posted_city();
    if ($city !== '' && !self::is_valid($city)) {
      $errors->add('ripex_invalid_city', __('Ciudad/comuna no válida. Selecciona una opción de la lista.', 'ripex-portal'));
    }
    return $errors;
  }

  public function validate_wp_registration($errors, $sanitized_user_login, $user_email) {
    $city = $this->posted_city();
    if ($city !== '' && !self::is_valid($city)) {
      $errors->add('ripex_invalid_city', __('Ciudad/comuna no válida. Selecciona una opción de la lista.', 'ripex-portal'));
    }
    return $errors;
  }

  public function validate_admin_user_edit($errors, $update, $user) {
    if (!isset($_POST[self::META_KEY])) return;

    $city = $this->posted_city();
    if ($city !== '' && !self::is_valid($city)) {
      $errors->add('ripex_invalid_city', __('Ciudad/comuna no válida. Selecciona una opción de la lista.', 'ripex-portal'));
    }
  }

  public function normalize_customer_meta($user_id) {
    if (!isset($_POST[self::META_KEY])) return;

    $city = $this->posted_city();
    $canonical = self::canonical($city);
    if ($canonical !== '') {
      update_user_meta($user_id, self::META_KEY, $canonical);
    }
  }
}
