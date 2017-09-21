<?php

namespace Drupal\media_entity_facebook\Plugin\MediaEntity\Type;

use Drupal\Core\Form\FormStateInterface;
use Drupal\media_entity\MediaInterface;
use Drupal\media_entity\MediaTypeBase;
use GuzzleHttp\Exception\TransferException;

/**
 * Provides media type plugin for Facebook.
 *
 * @MediaType(
 *   id = "facebook",
 *   label = @Translation("Facebook"),
 *   description = @Translation("Provides business logic and metadata for Facebook.")
 * )
 *
 * @todo On the long run we could switch to the facebook API which provides WAY
 *   more fields.
 */
class Facebook extends MediaTypeBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'source_field' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = [];

    $options = [];
    $bundle = $form_state->getFormObject()->getEntity();
    $allowed_field_types = ['string', 'string_long', 'link'];
    foreach ($this->entityFieldManager->getFieldDefinitions('media', $bundle->id()) as $field_name => $field) {
      if (in_array($field->getType(), $allowed_field_types) && !$field->getFieldStorageDefinition()->isBaseField()) {
        $options[$field_name] = $field->getLabel();
      }
    }

    $form['source_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field with source information'),
      '#description' => $this->t('Field on media entity that stores facebook embed code or URL. You can create a bundle without selecting a value for this dropdown initially. This dropdown can be populated after adding fields to the bundle.'),
      '#default_value' => empty($this->configuration['source_field']) ? NULL : $this->configuration['source_field'],
      '#options' => $options,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function providedFields() {
    return [
      'author_name',
      'width',
      'height',
      'url',
      'html',
    ];
  }

  /**
   * Returns the oembed data for a Facebook post.
   *
   * @param string $url
   *   The URL to the facebook post.
   *
   * @return bool|array
   *   FALSE if there was a problem retrieving the oEmbed data, otherwise
   *   an array of the data is returned.
   */
  protected function oEmbed($url) {
    static $memory_cache;

    if (!isset($memory_cache)) {
      $memory_cache = [];
    }

    if (!isset($memory_cache[$url])) {
      $endpoint = $this->getApiEndpointUrl($url) . '?url=' . $url;

      try {
        $response = \Drupal::httpClient()->get($endpoint, ['timeout' => 5]);
        $decoded = json_decode((string) $response->getBody(), TRUE);
        $memory_cache[$url] = $decoded;
      }
      catch (TransferException $e) {
        \Drupal::logger('media_entity_facebook')->error($this->t('Error retrieving oEmbed data for a Facebook media entity: @error', ['@error' => $e->getMessage()]));
        return FALSE;
      }
    }
    return $memory_cache[$url];
  }

  /**
   * Runs preg_match on embed code/URL.
   *
   * @param MediaInterface $media
   *   Media object.
   *
   * @return string|false
   *   The facebook url or FALSE if there is no field or it contains invalid
   *   data.
   */
  protected function getFacebookUrl(MediaInterface $media) {
    if (isset($this->configuration['source_field'])) {
      $source_field = $this->configuration['source_field'];
      if ($media->hasField($source_field)) {
        $property_name = $media->{$source_field}->first()->mainPropertyName();
        $embed = $media->{$source_field}->{$property_name};

        return static::parseFacebookEmbedField($embed);
      }
    }

    return FALSE;
  }

  /**
   * Return the appropriate Facebook oEmbed API endpoint for the content URL.
   *
   * @param string $content_url
   *   The content URL contains the URL to the resource.
   *
   * @return string
   *   The oEmbed endpoint URL.
   */
  protected function getApiEndpointUrl($content_url) {
    if (preg_match('/\/videos\//', $content_url) || preg_match('/\/video.php\//', $content_url)) {
      return 'https://www.facebook.com/plugins/video/oembed.json/';
    }
    else {
      return 'https://www.facebook.com/plugins/post/oembed.json/';
    }
  }

  /**
   * Extract a Facebook content URL from a string.
   *
   * Typically users will enter an iframe embed code that Facebook provides, so
   * which needs to be parsed to extract the actual post URL.
   *
   * Users may also enter the actual content URL - in which case we just return
   * the value if it matches our expected format.
   *
   * @param string $data
   *   The string that contains the Facebook post URL.
   *
   * @return string|bool
   *   The post URL, or FALSE if one cannot be found.
   */
  public static function parseFacebookEmbedField($data) {
    $data = trim($data);

    // Ideally we would verify that the content URL matches an exact pattern,
    // but Facebook has a ton of different ways posts/notes/videos/etc URLs can
    // be formatted, so it's not practical to try and validate them. Instead,
    // just validate that the content URL is from the facebook domain.
    $content_url_regex = '/^https:\/\/(www\.)?facebook\.com\//i';

    if (preg_match($content_url_regex, $data)) {
      return $data;
    }
    else {
      // Check if the user entered an iframe embed instead, and if so,
      // extract the post URL from the iframe src.
      $doc = new \DOMDocument();
      if (@$doc->loadHTML($data)) {
        $iframes = $doc->getElementsByTagName('iframe');
        if ($iframes->length > 0 && $iframes->item(0)->hasAttribute('src')) {
          $iframe_src = $iframes->item(0)->getAttribute('src');
          $uri_parts = parse_url($iframe_src);
          if ($uri_parts !== FALSE && isset($uri_parts['query'])) {
            parse_str($uri_parts['query'], $query_params);
            if (isset($query_params['href']) && preg_match($content_url_regex, $query_params['href'])) {
              return $query_params['href'];
            }
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getField(MediaInterface $media, $name) {
    $content_url = $this->getFacebookUrl($media);
    if ($content_url === FALSE) {
      return FALSE;
    }

    $data = $this->oEmbed($content_url);
    if ($data === FALSE) {
      return FALSE;
    }

    switch ($name) {
      case 'author_name':
        return $data['author_name'];

      case 'width':
        return $data['width'];

      case 'height':
        return $data['height'];

      case 'url':
        return $data['url'];

      case 'html':
        return $data['html'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function thumbnail(MediaInterface $media) {
    // @todo Add support for thumbnails on the longrun.
    return $this->getDefaultThumbnail();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultThumbnail() {
    return $this->config->get('icon_base') . '/facebook.png';
  }

  /**
   * {@inheritdoc}
   */
  public function attachConstraints(MediaInterface $media) {
    parent::attachConstraints($media);

    if (isset($this->configuration['source_field'])) {
      $source_field_name = $this->configuration['source_field'];
      if ($media->hasField($source_field_name)) {
        foreach ($media->get($source_field_name) as &$embed_code) {
          /** @var \Drupal\Core\TypedData\DataDefinitionInterface $typed_data */
          $typed_data = $embed_code->getDataDefinition();
          $typed_data->addConstraint('FacebookEmbedCode');
        }
      }
    }
  }

}
