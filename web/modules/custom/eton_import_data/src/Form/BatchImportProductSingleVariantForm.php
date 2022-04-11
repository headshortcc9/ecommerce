<?php

namespace Drupal\eton_import_data\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_price\Price;

/**
 * Class BatchImportProductSingleVariantForm.
 */
class BatchImportProductSingleVariantForm extends FormBase {

  const SKU = 'External ID';
  const NAME = 'Name';
  const BARCODE = 'Barcode';
  const SALES_PRICE = 'Sales Price';
  const CATEGORY = 'pos_categ_id';
  const BRAND = 'Brand';
  const UNIT = 'Đơn vị tính';

  const CSV_HEADER = [
    self::SKU,
    self::NAME,
    self::BARCODE,
    self::SALES_PRICE,
    self::CATEGORY,
    self::UNIT,
    self::BRAND,
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'batch_import_product_single_variant_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $headerText = implode('|', self::CSV_HEADER);
    $samplePath = Drupal::service('extension.list.module')->getPath('eton_import_data') . '/assets/data/eton_vinamilk_sample.csv';
    $form['csv'] = [
      '#type' => 'file',
      '#title' => t('CSV'),
      '#description' => t('CSV format only, header: %header. <a href=/:samplePath>Example</a>', [
        '%header' => $headerText,
        ':samplePath' => $samplePath,
      ]),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $files = $this->getRequest()->files->get('files', []);
    if (empty($files['csv']) || !$files['csv']->isValid()) {
      $form_state->setErrorByName('csv', $this->t('The file could not be uploaded.'));
    }
  }

  /**
   * Read csv to array.
   */
  public static function readCsv($fileUri) {
    $records = [];

    // Open CSV file.
    $handle = fopen($fileUri, 'r');

    try {
      // Get the column names.
      $column_names = fgetcsv($handle);
      foreach ($column_names as $index => $name) {
        $column_names[$index] = $name;
      }
      $header = self::CSV_HEADER;
      if (count(array_intersect($header, $column_names)) != count($header)) {
        fclose($handle);
        $headerText = implode('|', $header);
        \Drupal::messenger()
          ->addError("The uploaded file does not have the correct structure: {$headerText} !");
        return $records;
      }

      while (!feof($handle)) {
        $values = fgetcsv($handle);
        // Complete ignored empty rows.
        if (empty($values) || $values == [''] || empty(trim(implode("", $values)))) {
          continue;
        }

        $record = array_combine($column_names, $values);
        foreach ($record as $key => $value) {
          $record[$key] = trim($value);
        }
        $records[] = $record;
      }
      fclose($handle);
    } catch (\Exception $ex) {
      \Drupal::messenger()->addError($ex->getMessage());
      fclose($handle);
    }

    return $records;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $validators = ['file_validate_extensions' => ['csv']];
    $files = file_save_upload('csv', $validators);
    $file = ($files) ? reset($files) : NULL;

    if ($file) {
      // Normalize carriage returns.
      // This prevent issues with CSV files created in Excel.
      $contents = file_get_contents($file->getFileUri());
      $contents = preg_replace('~\R~u', "\r\n", $contents);
      file_put_contents($file->getFileUri(), $contents);

      $records = self::readCsv($file->getFileUri());
      $recordChunks = array_chunk($records, 100);
      $operations = [];

      foreach ($recordChunks as $recordChunk) {
        $operations[] = [
          '\Drupal\eton_import_data\Form\BatchImportProductSingleVariantForm::processItem',
          [
            $recordChunk,
          ],
        ];
      }

      $total = count($records);
      $batch = [
        'title' => t('Processing Import Product Single Variant'),
        'operations' => $operations,
        'init_message' => \Drupal::translation()->formatPlural($total, 'Processing 1 record.', "Processing @count records."),
        'finished' => '\Drupal\eton_import_data\Form\BatchImportProductSingleVariantForm::processFinish',
      ];
      batch_set($batch);
    }
  }

  /**
   * processItem.
   */
  public static function processItem($data, &$context) {
    $context['results'][] = count($data);
    foreach ($data as $record) {
      try {
        $sku = $record[self::SKU];
        $name = $record[self::NAME];
        $barcode = $record[self::BARCODE];
        $salesPrice = $record[self::SALES_PRICE];
        $category = $record[self::CATEGORY];
        $brand = $record[self::BRAND];
        $unit = $record[self::UNIT];

        $categoryTid = Drupal::entityQuery('taxonomy_term')
          ->condition('vid', 'product_categories')
          ->condition('name', $category)
          ->execute();
        if (empty($categoryTid)) {
          throw new \Exception("Product category $category is not found.");
        }
        else {
          $categoryTid = reset($categoryTid);
        }

        $brandTid = Drupal::entityQuery('taxonomy_term')
          ->condition('vid', 'brands')
          ->condition('name', $brand)
          ->execute();
        if (empty($brandTid)) {
          throw new \Exception("Product brand $brand is not found.");
        }
        else {
          $brandTid = reset($brandTid);
        }

        $variant = Drupal::entityQuery('commerce_product_variation')
          ->condition('type', 'demoprodtype')
          ->condition('sku', $sku)
          ->execute();

        if (!empty($variant)) {
          throw new \Exception("Sku $sku is already exist.");
        }

        $variation = ProductVariation::create([
          'type' => 'demoprodtype',
          'title' => $name,
          'sku' => $sku,
          'price' => new Price($salesPrice, 'VND'),
        ]);
        $variation->save();

        $product = Product::create([
          'type' => 'demoprodtype',
          'title' => $name,
          'body' => $name,
          'field_barcode' => $barcode,
          'field_brand' => $brandTid,
          'field_product_categories' => $categoryTid,
          'field_sale_price' => new Price($salesPrice, 'VND'),
          'variations' => [$variation],
        ]);
        $product->save();
      }
      catch (\Exception $ex) {
        Drupal::logger('eton_import_data')
          ->error("Can not import Product SKU = $sku, Error: " . $ex->getMessage());
      }
    }
  }

  /**
   * processFinish.
   */
  public static function processFinish($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    if ($success) {
      // Here we could do something meaningful with the results.
      // We just display the number of nodes we processed...
      $count = array_sum($results);
      $messenger->addMessage(\Drupal::translation()->formatPlural(
        $count, '1 result processed.', '@count results processed.')
      );
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $messenger->addMessage(
        t('An error occurred while processing @operation with arguments : @args',
          [
            '@operation' => $error_operation[0],
            '@args' => print_r($error_operation[0], TRUE),
          ]
        )
      );
    }
  }

}
