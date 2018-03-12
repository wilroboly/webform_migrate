<?php

namespace Drupal\webform_migrate\Plugin\migrate\source\d7;

use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\migrate\Event\ImportAwareInterface;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\RollbackAwareInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;
use Drupal\node\Entity\Node;
use Drupal\webform\Entity\Webform;
use Symfony\Component\Yaml\Yaml;

/**
 * Drupal 7 webform source from database.
 *
 * @MigrateSource(
 *   id = "d7_webform"
 * )
 */
class D7Webform extends DrupalSqlBase implements ImportAwareInterface, RollbackAwareInterface {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('webform', 'wf');
    $query->innerJoin('node', 'n', 'wf.nid=n.nid');
    $query->innerJoin('node_revision', 'nr', 'n.vid=nr.vid');
    $webform_fields = $this->getWebformTableFields();

    $query->fields('wf', $webform_fields)
      ->fields('nr', ['title']);

    $query->addField('n', 'uid', 'node_uid');

    return $query;
  }

  /**
   * Get D7 webform columns names.
   *
   * @return array
   *   The webform columns names list.
   */
  private function getWebformTableFields() {
    // Get the webform table columns to ensure all the requested fields
    // correspond to the webform version that is used for migrations, in some
    // old version some fields could not exist so we need to ignore those.
    // @var \Drupal\Core\Database\Statement $query
    $query = $this->getDatabase()->query("SHOW COLUMNS FROM webform");
    return array_keys($query->fetchAllAssoc('Field'));
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $this->filterDefaultFormat = $this->variableGet('filter_default_format', '1');
    return parent::initializeIterator();
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'nid' => $this->t('Node ID'),
      'title' => $this->t('Webform title'),
      'node_uid' => $this->t('Webform author'),
      'confirmation' => $this->t('Confirmation message'),
      'confirmation_type' => $this->t('Confirmation type'),
      'status' => $this->t('Status'),
      'submit_text' => $this->t('Submission text'),
      'submit_limit' => $this->t('Submission limit'),
      'submit_interval' => $this->t('Submission interval'),
      'submit_notice' => $this->t('Submission notice'),
      'allow_draft' => $this->t('Draft submission allowed'),
      'redirect_url' => $this->t('Redirect url'),
      'block' => $this->t('Block'),
      'auto_save' => $this->t('Automatic save'),
      'total_submit_limit' => $this->t('Total submission limit'),
      'total_submit_interval' => $this->t('Total submission interval'),
      'webform_id' => $this->t('Id to be used for  Webform'),
      'elements' => $this->t('Elements for the Webform'),
      'confirmation_format' => $this->t('The filter_format.format of the confirmation message.'),
      'auto_save' => $this->t('Boolean value for whether submissions to this form should be auto-saved between pages.'),
      'progressbar_bar' => $this->t('Boolean value indicating if the bar should be shown as part of the progress bar.'),
      'progressbar_page_number' => $this->t('Boolean value indicating if the page number should be shown as part of the progress bar.'),
      'progressbar_percent' => $this->t('Boolean value indicating if the percentage complete should be shown as part of the progress bar.'),
      'progressbar_pagebreak_labels' => $this->t('Boolean value indicating if the pagebreak labels should be included as part of the progress bar.'),
      'progressbar_include_confirmation' => $this->t('Boolean value indicating if the confirmation page should count as a page in the progress bar.'),
      'progressbar_label_first' => $this->t('Label for the first page of the progress bar.'),
      'progressbar_label_confirmation' => $this->t('Label for the last page of the progress bar.'),
      'preview' => $this->t('Boolean value indicating if this form includes a page for previewing the submission.'),
      'preview_next_button_label' => $this->t('The text for the button that will proceed to the preview page.'),
      'preview_prev_button_label' => $this->t('The text for the button to go backwards from the preview page.'),
      'preview_title' => $this->t('The title of the preview page, as used by the progress bar.'),
      'preview_message' => $this->t('Text shown on the preview page of the form.'),
      'preview_message_format' => $this->t('The filter_format.format of the preview page message.'),
      'preview_excluded_components' => $this->t('Comma-separated list of component IDs that should not be included in this form’s confirmation page.'),
      'next_serial' => $this->t('The serial number to give to the next submission to this webform.'),
      'confidential' => $this->t('Boolean value for whether to anonymize submissions.'),
    ];

    // Get the current webform column names and ignore any nonexistent field.
    $current_fields = $this->getWebformTableFields();
    // Remove fields that do not exists on current webform table.
    foreach ($fields as $field_name => $description) {
      if (!isset($current_fields[$field_name])) {
        unset($fields[$field_name]);
      }
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $elements = '';

    // Webform prior 7.x-4.8 releases are complicated to support due important
    // changes on source tables schema, webform_conditional_actions table do
    // not exists so migration conditionals before that release don't migrate.
    // In those versions we skip row migration and log a message to suggest an
    // D7 webform upgrade. For details see:
    // https://www.drupal.org/project/webform/releases/7.x-4.8
    if (!$this->checkTableExists('webform_conditional_actions')) {
      $message = "The minimum webform source version is 7.x-4.8,
        please upgrade your D7 webform module to recent release before migration.";
      throw new MigrateSkipRowException($message);
    }

    $nid = $row->getSourceProperty('nid');
    $webform = $this->buildFormElements($nid);
    $elements .= $webform['elements'];
    $handlers = $this->buildEmailHandlers($nid, $webform['xref']);
    $access = $this->buildAccessTable($nid);

    $confirm = $row->getSourceProperty('redirect_url');
    if ($confirm == '<confirmation>') {
      $confirm_type = 'page';
      $row->setSourceProperty('redirect_url', '');
    }
    elseif ($confirm == '<none>') {
      $confirm_type = 'inline';
      $row->setSourceProperty('redirect_url', '');
    }
    else {
      $confirm_type = 'url';
    }
    if ($row->getSourceProperty('submit_limit') < 0) {
      $row->setSourceProperty('submit_limit', '');
    }
    if ($row->getSourceProperty('total_submit_limit') < 0) {
      $row->setSourceProperty('total_submit_limit', '');
    }
    $row->setSourceProperty('confirmation_type', $confirm_type);
    $row->setSourceProperty('elements', $elements);
    $row->setSourceProperty('handlers', $handlers);
    $row->setSourceProperty('access', $access);
    $row->setSourceProperty('status', $row->getSourceProperty('status') ? 'open' : 'closed');

    // Generate a machine name based on title.
    $transliteration = \Drupal::service('transliteration');
    $title = $row->getSourceProperty('title');
    $machine_name = $transliteration->transliterate($title, LanguageInterface::LANGCODE_DEFAULT, '_');
    $machine_name = strtolower($machine_name);
    $machine_name = preg_replace('/[^a-z0-9_]+/', '_', $machine_name);
    $machine_name = preg_replace('/_+/', '_', $machine_name);
    $row->setSourceProperty('webform_id', $machine_name);

    // Generate a unique ID for the webform.
    $uuid = \Drupal::service('uuid');
    $row->setSourceProperty('webform_uuid', substr($uuid->generate(), 0, 32));

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['nid']['type'] = 'integer';
    $ids['nid']['alias'] = 'wf';
    return $ids;
  }

  /**
   * Build form elements from webform component table.
   */
  private function buildFormElements($nid) {
    // TODO : Use yaml_emit http://php.net/manual/en/function.yaml-emit.php
    $output = '';

    $query = $this->select('webform_component', 'wc');
    $query->fields('wc', [
      'nid',
      'cid',
      'pid',
      'form_key',
      'name',
      'type',
      'value',
      'extra',
      'required',
      'weight',
    ]);
    $components = $query->condition('nid', $nid)->orderBy('pid')->orderBy('weight')->execute();
    $children = [];
    $parents = [];
    $elements = [];
    $xref = [];

    // Build an array of elements in the correct order for rendering based on
    // pid and weight and a cross reference array to match cid with form_key
    // used by email handler.
    $multiPage = FALSE;
    foreach ($components as $component) {
      $xref[$component['cid']] = $component['form_key'];
      if ($component['type'] == 'pagebreak') {
        // Pagebreak found so we have a multi-page form.
        $multiPage = TRUE;
      }
      $children[$component['pid']][] = $component['cid'];
      $parents[$component['cid']][] = $component['pid'];
      $elements[$component['cid']] = $component;
    }
    // Keeps track of the parents we have to process, the last entry is used
    // for the next processing step.
    $process_parents = [];
    $process_parents[] = 0;
    $elements_tree = [];
    // Loops over the parent components and adds its children to the tree array.
    // Uses a loop instead of a recursion, because it's more efficient.
    while (count($process_parents)) {
      $parent = array_pop($process_parents);
      // The number of parents determines the current depth.
      $depth = count($process_parents);
      if (!empty($children[$parent])) {
        $has_children = FALSE;
        $child = current($children[$parent]);
        do {
          if (empty($child)) {
            break;
          }

          $element = &$elements[$child];
          $element['depth'] = $depth;
          // We might get element with same form_key d8 doesn't like that so
          // rename it.
          if ($depth > 0) {
            $element['form_key'] = $element['form_key'] . '_' . $element['pid'];
          }

          unset($element['pid']);
          $elements_tree[] = $element;
          if (!empty($children[$element['cid']])) {
            $has_children = TRUE;
            // We have to continue with this parent later.
            $process_parents[] = $parent;
            // Use the current component as parent for the next iteration.
            $process_parents[] = $element['cid'];
            // Reset pointers for child lists because we step in there more
            // often with multi parents.
            reset($children[$element['cid']]);
            // Move pointer so that we get the correct term the next time.
            next($children[$parent]);
            break;

          }
        } while ($child = next($children[$parent]));

        if (!$has_children) {
          // We processed all components in this hierarchy-level.
          reset($children[$parent]);
        }
      }
    }

    // If form has multiple pages then start first page automatically.
    if ($multiPage) {
      $pageCnt = 1;
      $current_page = 'wizard_page_1';
      $output .= "first_page:\n  '#type': webform_wizard_page\n  '#title': {" . $current_page . "_title}\n  '#open': true\n";
      $current_page_title = t('Page') . ' ' . $pageCnt++;
    }

    foreach ($elements_tree as $element) {
      // Rename fieldsets to it's own unique key.
      if ($element['type'] == 'fieldset' && strpos($element['form_key'], 'fieldset') === FALSE) {
        $element['form_key'] = 'fieldset_' . $element['form_key'];
      }

      // If this is a multi-page form then indent all elements one level
      // to allow for page elements.
      if ($multiPage && $element['type'] != 'pagebreak') {
        $element['depth'] += 1;
      }
      $indent = str_repeat(' ', $element['depth'] * 2);
      $extra = unserialize($element['extra']);
      $description = trim($this->cleanString($extra['description']));

      // Create an option list if there are items for this element.
      $options = '';
      $valid_options = [];
      $items = [];
      if (!empty($extra['items'])) {
        $items = explode("\n", trim($extra['items']));
        $ingroup = '';
        foreach ($items as $item) {
          $item = trim($item);
          if (!empty($item)) {
            if (preg_match('/^<(.*)>$/', $item, $matches)) {
              // Handle option groups.
              $options .= "$indent    '" . $matches[1] . "':\n";
              $ingroup = str_repeat(' ', 2);
            }
            else {
              $option = explode('|', $item);
              $valid_options[] = $option[0];
              if (count($option) == 2) {
                $options .= "$indent$ingroup    " . $option[0] . ": " . $option[1] . "\n";
              }
              else {
                $options .= "$indent$ingroup    " . $option[0] . ": " . $option[0] . "\n";
              }
            }
          }
        }
      }

      // Replace any tokens in the value.
      if (!empty($element['value'])) {
        $element['value'] = $this->replaceTokens($element['value']);
      }

      $markup = $indent . $element['form_key'] . ":\n";
      switch ($element['type']) {
        case 'fieldset':
          if ($multiPage && empty($current_page_title)) {
            $current_page_title = $element['name'];
          }
          $markup .= "$indent  '#type': fieldset\n$indent  '#open': true\n";
          break;

        case 'textfield':
          $markup .= "$indent  '#type': textfield\n";
          if (!empty($extra['width'])) {
            $markup .= "$indent  '#size': " . $extra['size'] . "\n";
          }
          break;

        case 'textarea':
          $markup .= "$indent  '#type': textarea\n";
          break;

        case 'select':
          if (!empty($extra['aslist'])) {
            $select_type = 'select';
          }
          // Count from parsed options array.
          elseif (!empty($extra['multiple']) && count($items) > 1) {
            $select_type = 'checkboxes';
          }
          elseif (!empty($extra['multiple']) && count($items) == 1) {
            $select_type = 'checkbox';
            list($key, $description) = explode('|', $items[0]);
            $description = trim($this->cleanString($description));
            // Set description attribute when is filled.
            if (!empty($description)) {
              $markup .= "$indent  '#description': \"" . $description . "\"\n";
            }
          }
          else {
            $select_type = 'radios';
          }
          $markup .= "$indent  '#type': $select_type\n";
          $markup .= "$indent  '#options':\n" . $options;
          if (!empty($extra['multiple'])) {
            $markup .= "$indent  '#multiple': true\n";
          }
          break;

        case 'email':
          $markup .= "$indent  '#type': email\n$indent  '#size': 20\n";
          break;

        case 'number':
          if ($extra['type'] == 'textfield') {
            $markup .= "$indent  '#type': textfield\n$indent  '#size': 20\n";
          }
          elseif ($extra['type'] == 'select') {
            $markup .= "$indent  '#type': select\n";
            $markup .= "$indent  '#options':\n" . $options;
            $min = $extra['min'];
            $max = $extra['max'];
            $step = !empty($extra['step']) ? $extra['step'] : 1;
            for ($value = $min; $value <= $max; $value += $step) {
              $markup .= "$indent    " . $value . ": " . $value . "\n";
            }
          }
          if (isset($extra['min'])) {
            $markup .= "$indent  '#min': " . $extra['min'] . "\n";
          }
          if (isset($extra['max'])) {
            $markup .= "$indent  '#max': " . $extra['max'] . "\n";
          }
          if (isset($extra['step'])) {
            $markup .= "$indent  '#step': " . $extra['step'] . "\n";
          }
          if (isset($extra['unique'])) {
            $unique = ($extra['unique']) ? 'true' : 'false';
            $markup .= "$indent  '#unique': " . $unique . "\n";
          }
          break;

        case 'markup':
          $markup .= "$indent  '#type': processed_text\n";
          $markup .= "$indent  '#format': full_html\n";
          $markup .= "$indent  '#title_display': invisible\n";
          $markup .= "$indent  '#text': \"" . $this->cleanString($element['value']) . "\"\n";
          $element['value'] = '';
          break;

        case 'file':
          $exts = '';
          if (!empty($extra['filtering']['types'])) {
            $types = $extra['filtering']['types'];
            if (!empty($extra['filtering']['addextensions'])) {
              $add_types = explode(',', $extra['filtering']['addextensions']);
              $types = array_unique(array_merge($types, array_map('trim', $add_types)));
            }
            $exts = implode(',', $types);
          }
          $filesize = '';
          if (!empty($extra['filtering']['size'])) {
            $filesize = $extra['filtering']['size'] / 1000;
          }
          $markup .= "$indent  '#type': managed_file\n";
          $markup .= "$indent  '#max_filesize': '$filesize'\n";
          $markup .= "$indent  '#file_extensions': '$exts'\n";
          if (!empty($extra['width'])) {
            $markup .= "$indent  '#size': " . $extra['width'] . "\n";
          }
          break;

        case 'date':
          $markup .= "$indent  '#type': date\n";
          /* if (!empty($element['value'])) {
           * $element['value'] = date('Y-m-d', strtotime($element['value']));
           * }
           */
          break;

        case 'time':
          $markup .= "$indent  '#type': time\n";
          if (!empty($extra['hourformat'])) {
            if ($extra['hourformat'] == '12-hour') {
              $markup .= "$indent  '#time_format': 'g:i A'\n";
            }
            elseif ($extra['hourformat'] == '24-hour') {
              $markup .= "$indent  '#time_format': 'H:i'\n";
            }
          }

          break;

        case 'hidden':
          $markup .= "$indent  '#type': hidden\n";
          break;

        case 'pagebreak':
          $output = str_replace('{' . $current_page . '_title}', $current_page_title, $output);
          $current_page = $element['form_key'];
          $markup .= "$indent  '#type': webform_wizard_page\n  '#open': true\n  '#title': {" . $current_page . "_title}\n";
          $current_page_title = t('Page') . ' ' . $pageCnt++;
          break;
      }

      // Add common fields.
      if (!empty($element['value']) && (empty($valid_options) || in_array($element['value'], $valid_options))) {
        $markup .= "$indent  '#default_value': " . $element['value'] . "\n";
      }
      if (!empty($extra['field_prefix'])) {
        $markup .= "$indent  '#field_prefix': " . $extra['field_prefix'] . "\n";
      }
      if (!empty($extra['field_suffix'])) {
        $markup .= "$indent  '#field_suffix': " . $extra['field_suffix'] . "\n";
      }
      if (!empty($extra['title_display']) && $extra['title_display'] != 'before') {
        $title_display = $extra['title_display'];
        if ($title_display == 'none') {
          $title_display = 'invisible';
        }
        $markup .= "$indent  '#title_display': " . $title_display . "\n";
      }
      if ($element['type'] != 'pagebreak') {
        $markup .= "$indent  '#title': \"" . $element['name'] . "\"\n";
        // Set description attribute when is filled.
        if (!empty($description)) {
          $markup .= "$indent  '#description': \"" . $description . "\"\n";
        }
      }
      if (!empty($element['required'])) {
        $markup .= "$indent  '#required': true\n";
      }

      // Build contionals.
      if ($states = $this->buildConditionals($element, $elements)) {
        foreach ($states as $key => $values) {
          // On empty conditional key, build one to avoid YAML syntax violation
          // that happens when leave it empty.
          if (empty($key)) {
            $message = "Missing conditional key on webform {$element['nid']},
              {$element['form_key']} element.";
            throw new MigrateSkipRowException($message);
          }

          $markup .= "$indent  '#states':\n";
          $markup .= "$indent    $key:\n";
          foreach ($values as $value) {
            foreach ($value as $name => $item) {
              $markup .= "$indent      " . Yaml::dump($name, 2, 2) . ":\n";
              $markup .= "$indent        " . Yaml::dump($item, 2, 2);
            }
          }
        }
      }

      $output .= $markup;
    }

    if ($multiPage) {
      // Replace the final page title.
      $output = str_replace('{' . $current_page . '_title}', $current_page_title, $output);
    }
    return ['elements' => $output, 'xref' => $xref];
  }

  /**
   * Build conditionals and translate them to states api in D8.
   */
  private function buildConditionals($element, $elements) {
    $nid = $element['nid'];
    $cid = $element['cid'];
    $extra = unserialize($element['extra']);

    // Checkboxes : ':input[name="add_more_locations_24[yes]"]':.
    $query = $this->select('webform_conditional', 'wc');
    $query->fields('wc', [
      'nid',
      'rgid',
      'andor',
      'weight',
    ]);
    $query->condition('wc.nid', $nid);

    $query->innerJoin('webform_conditional_actions', 'wca', 'wca.nid=wc.nid AND wca.rgid=wc.rgid');
    $query->fields('wca', [
      'aid',
      'target_type',
      'target',
      'invert',
      'action',
      'argument',
    ]);
    $query->condition('wca.target', $cid);
    $query->innerJoin('webform_conditional_rules', 'wcr', 'wcr.nid=wca.nid AND wcr.rgid=wca.rgid');

    $query->fields('wcr', [
      'rid',
      'source_type',
      'source',
      'operator',
      'value',
    ]);

    $conditions = $query->execute();
    $states = [];
    if (!empty($conditions)) {
      foreach ($conditions as $condition) {
        // Element states.
        switch ($condition['action']) {
          case 'show':
            $element_state = $condition['invert'] ? 'invisible' : 'visible';
            break;

          case 'require':
            $element_state = $condition['invert'] ? 'optional' : 'required';
            break;

          case 'set':
            // Nothing found in D8.
            break;

        }

        // Condition states.
        $operator_value = trim($condition['value']);
        $depedent = $elements[$condition['source']];
        $depedent_extra = unserialize($depedent['extra']);
        // Convert string items to array.
        $items = [];
        if (isset($depedent_extra['items'])) {
          $items = explode("\n", trim($depedent_extra['items']));
        }

        switch ($condition['operator']) {
          case 'equal':
            $element_condition = ['value' => $operator_value];
            break;

          case 'not_equal':
            // There is no handler for this in D8 so we do the reverse.
            $element_state = $condition['invert'] ? 'visible' : 'invisible';
            $element_condition = ['value' => $operator_value];
            break;

          case 'less_than':
          case 'less_than_equal':
          case 'greater_than':
          case 'greater_than_equal':
            // Nothing in D8 to handle these.
            break;

          case 'empty':
            if ($operator_value == 'checked') {
              $element_condition = ['unchecked' => TRUE];
            }
            else {
              $element_condition = ['empty' => TRUE];
            }
            break;

          case 'not_empty':
            if ($operator_value == 'checked') {
              $element_condition = ['checked' => TRUE];
            }
            else {
              $element_condition = ['filled' => FALSE];
            }
            break;

        }

        // Specially handle the checkboxes, radios on multi-selection options
        // elements that has the square bracket value as part of element name.
        if (!$depedent_extra['aslist'] && $depedent_extra['multiple'] && count($items) > 1) {
          $depedent['form_key'] = $depedent['form_key'] . "[$operator_value]";
          if ($depedent['type'] == 'select') {
            $element_condition = ['checked' => TRUE];
          }
        }

        $states[$element_state][] = [':input[name="' . $depedent['form_key'] . '"]' => $element_condition];
      }
      if (empty($states)) {
        return FALSE;
      }
      return $states;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Check if table exists on migration DB.
   *
   * @param string $table_name
   *   The table name to check existance for.
   *
   * @return bool
   *   TRUE if table exists, FALSE otherwise.
   */
  private function checkTableExists($table_name) {
    $table_exists = $this->getDatabase()
      ->schema()
      ->tableExists($table_name);
    return $table_exists;
  }

  /**
   * Build email handlers from webform emails table.
   */
  private function buildEmailHandlers($nid, $xref) {

    $query = $this->select('webform_emails', 'we');
    $query->fields('we', [
      'nid',
      'eid',
      'email',
      'subject',
      'from_name',
      'from_address',
      'template',
      'excluded_components',
      'html',
      'attachments',
    ]);
    $emails = $query->condition('nid', $nid)->execute();

    $handlers = [];
    foreach ($emails as $email) {
      $id = 'email_' . $email['eid'];
      foreach (['email', 'subject', 'from_name', 'from_address'] as $field) {
        if (!empty($email[$field]) && is_numeric($email[$field]) && !empty($xref[$email[$field]])) {
          $email[$field] = "[webform-submission:values:{$xref[$email[$field]]}:raw]";
        }
      }
      $excluded = [];
      if (!empty($email['excluded_components'])) {
        $excludes = explode(',', $email['excluded_components']);
        foreach ($excludes as $exclude) {
          if (!empty($xref[$exclude])) {
            $excluded[$xref[$exclude]] = $xref[$exclude];
          }
        }
      }
      $handlers[$id] = [
        'id' => 'email',
        'label' => 'Email ' . $email['eid'],
        'handler_id' => $id,
        'status' => 1,
        'weight' => $email['eid'],
        'settings' => [
          'to_mail' => $email['email'],
          'from_mail' => $email['from_address'],
          'from_name' => $email['from_name'],
          'subject' => $email['subject'],
          'body' => $email['template'],
          'html' => $email['html'],
          'attachments' => $email['attachments'],
          'excluded_elements' => $excluded,
        ],
      ];
    }
    return $handlers;
  }

  /**
   * Build access table from webform roles table.
   */
  private function buildAccessTable($nid) {

    $query = $this->select('webform_roles', 'wr');
    $query->innerJoin('role', 'r', 'wr.rid=r.rid');
    $query->fields('wr', ['nid', 'rid'])
      ->fields('r', ['name']);
    $wf_roles = $query->condition('nid', $nid)->execute();

    $roles = [];
    // Handle rids 1 and 2 as per user_update_8002.
    $map = [
      1 => 'anonymous',
      2 => 'authenticated',
    ];
    foreach ($wf_roles as $role) {
      if (isset($map[$role['rid']])) {
        $roles[] = $map[$role['rid']];
      }
      else {
        $roles[] = str_replace(' ', '_', strtolower($role['name']));
      }
    }

    $access = [
      'create' => [
        'roles' => $roles,
        'users' => [],
      ],
    ];

    return $access;
  }

  /**
   * Translate webform tokens into regular tokens.
   *
   * %uid - The user id (unsafe)
   * %username - The name of the user if logged in.
   *                       Blank for anonymous users. (unsafe)
   * %useremail - The e-mail address of the user if logged in.
   *                       Blank for anonymous users. (unsafe)
   * %ip_address - The IP address of the user. (unsafe)
   * %site - The name of the site
   *             (i.e. Northland Pioneer College, Arizona) (safe)
   * %date - The current date, formatted according
   *              to the site settings.(safe)
   * %nid - The node ID. (safe)
   * %title - The node title. (safe)
   * %sid - The Submission id (unsafe)
   * %submission_url - The Submission url (unsafe)
   * %profile[key] - Any user profile field or value, such as %profile[name]
   *                         or %profile[profile_first_name] (unsafe)
   * %get[key] - Tokens may be populated from the URL by creating URLs of
   *                    the form http://example.com/my-form?foo=bar.
   *                    Using the token %get[foo] would print "bar". (safe)
   * %post[key] - Tokens may also be populated from POST values
   *                      that are submitted by forms. (safe)
   * %email[key] (unsafe)
   * %value[key] (unsafe)
   * %email_values (unsafe)
   * %cookie[key] (unsafe)
   * %session[key] (unsafe)
   * %request[key] (unsafe)
   * %server[key] (unsafe)
   *
   * Safe values are available to all users and unsafe values
   * should only be shown to authenticated users.
   */
  private function replaceTokens($str) {
    return $str;
  }

  /**
   * {@inheritdoc}
   */
  private function cleanString($str) {
    return str_replace(['"', "\n", "\r"], ["'", '\n', ''], $str);
  }

  /**
   * {@inheritdoc}
   */
  public function preImport(MigrateImportEvent $event) {}

  /**
   * {@inheritdoc}
   */
  public function postImport(MigrateImportEvent $event) {
    // Add the Webform field to the webform content type if it doesn't already
    // exist, skip when not defined because webform_node modules is not
    // installed and webform nodes are not processed.
    if (!$field_storage = FieldStorageConfig::loadByName('node', 'webform')) {
      return;
    }

    $field = FieldConfig::loadByName('node', 'webform', 'webform');
    if (empty($field)) {
      $field = entity_create('field_config', [
        'field_storage' => $field_storage,
        'bundle' => 'webform',
        'label' => 'Webform',
        'settings' => [],
      ]);
      $field->save();
      // Assign widget settings for the 'default' form mode.
      $display = entity_get_form_display('node', 'webform', 'default')->getComponent('webform');
      entity_get_form_display('node', 'webform', 'default')
        ->setComponent('webform', [
          'type' => $display['type'],
        ])
        ->save();
      // Assign display settings for the 'default' and 'teaser' view modes.
      $display = entity_get_display('node', 'webform', 'default')->getComponent('webform');
      entity_get_display('node', 'webform', 'default')
        ->setComponent('webform', [
          'label' => $display['label'],
          'type' => $display['type'],
        ])
        ->save();
      // The teaser view mode is created by the Standard profile and therefore
      // might not exist.
      $view_modes = \Drupal::entityManager()->getViewModes('node');
      if (isset($view_modes['teaser'])) {
        $display = entity_get_display('node', 'webform', 'teaser')->getComponent('webform');
        entity_get_display('node', 'webform', 'teaser')
          ->setComponent('webform', [
            'label' => $display['label'],
            'type' => $display['type'],
          ])
          ->save();
      }
    }

    // Attach any Webform created to the relevant webforms if
    // Webform exists and Webform exists and Webform field is empty.
    $webforms = $this->query()->execute();
    foreach ($webforms as $webform) {
      $webform_nid = $webform['nid'];
      $webform_id = 'webform_' . $webform_nid;
      $webform = Webform::load($webform_id);
      if (!empty($webform)) {
        $node = Node::load($webform_nid);
        if (!empty($node) && $node->getType() == 'webform') {
          if (empty($node->webform->target_id)) {
            $node->webform->target_id = $webform_id;
            $node->webform->status = 1;
            $node->save();
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preRollback(MigrateRollbackEvent $event) {}

  /**
   * {@inheritdoc}
   */
  public function postRollback(MigrateRollbackEvent $event) {
    // Remove any Webform from webform if webform no longer exists.
    $webforms = $this->query()->execute();
    foreach ($webforms as $webform) {
      $webform_nid = $webform['nid'];
      $webform_id = 'webform_' . $webform_nid;
      $webform = Webform::load($webform_id);
      if (empty($webform)) {
        $node = Node::load($webform_nid);
        if (!empty($node) && $node->getType() == 'webform') {
          if (!empty($node->webform->target_id) && $node->webform->target_id == $webform_id) {
            $node->webform->target_id = NULL;
            $node->save();
          }
        }
      }
    }
  }

}
