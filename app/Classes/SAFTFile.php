<?php
/* The SAFTFile class generates an XML file in the SAF-T format for tax reporting purposes, based on
data from a business's sales and payment transactions. */

namespace App\Classes;

use SimpleXMLElement;
use DOMDocument;
use App\Interfaces\SAFTFileInterface;
use App\Business;
use Illuminate\Support\Facades\Redirect;
use App\TaxRate;
use App\Http\Controllers\SellController;
use App\TransactionPayment;
use  App\Contact;
use  App\Product;

class SAFTFile implements SAFTFileInterface
{
    protected $business;

    public function __construct()
    {
        // get business data        
        $business_id = request()->session()->get('user.business_id');
        $this->business = Business::with(['currency', 'saf_t'])->where('id', $business_id)->first();

        // if saf-t settings are not configured then 
        if (!isset($this->business->saf_t)) {

            // parameters to show actual issue on business setting page
            $output = [
                'success' => 0,
                'msg' => "SAF-t settings are not configured yet! so you are unable to export SAF-t xml file"
            ];
            return Redirect::to(url("/business/settings"))->with('status', $output)->send();
        }
    }

    function generateXml()
    {

        // object for xml
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
        <AuditFile xmlns="urn:OECD:StandardAuditFile-Tax:AO_1.01_01" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
        </AuditFile>');

        // add header section to xml
        $this->HeaderSection($xml);

        // add MasterFiles section to xml
        $this->MasterFilesSection($xml);

        // add header section to xml
        $this->SourceDocumentsSection($xml);

        // save and download saf-t xml file
        $this->ExportToXml($xml);
    }

    function ExportToXml($xml)
    {
        // Saf-t xml file_name
        $file_name =  $this->business->name . request()->from . '-' . request()->to . '.xml';

        // save xml file in public/SAF-T-tax-exported-files
        $sxe = new SimpleXMLElement($xml->asXML());
        $dom = new DOMDocument('1,0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($sxe->asXML());
        $xml_file_name = "SAF-T-tax-exported-files/" . $file_name;
        $dom->save($xml_file_name);

        return Redirect::to(url($xml_file_name))->send();
    }

    function HeaderSection(&$xml)
    {

        // Saf-t file header data is stored in $header var
        $header = array();
        $header['AuditFileVersion'] = '1.01_01';
        $header['CompanyID'] = $this->business->saf_t->company_id;
        $header['TaxRegistrationNumber'] = $this->business->saf_t->tax_registration_number;
        $header['TaxAccountingBasis'] = $this->business->saf_t->tax_accounting_basis;
        $header['CompanyName'] = $this->business->saf_t->company_name;
        $header['CompanyAddress'] =
            [
                'AddressDetail' => $this->business->saf_t->company_address_detail,
                'City' => $this->business->saf_t->company_address_city,
                'Country' => $this->business->saf_t->company_address_country,
            ];
        $header['FiscalYear'] = date('Y', strtotime(request()->to));
        $header['StartDate'] = request()->from;
        $header['EndDate'] = request()->to;
        $header['CurrencyCode'] = $this->business->currency->code;
        $header['DateCreated'] = date('Y-m-d');
        $header['TaxEntity'] = $this->business->saf_t->tax_entity;
        $header['ProductCompanyTaxID'] = $this->business->saf_t->product_company_tax_id;
        $header['SoftwareValidationNumber'] = $this->business->saf_t->software_validation_number;
        $header['ProductID'] = $this->business->saf_t->product_id;
        $header['ProductVersion'] = '1.0';

        // add upper element Header in the $header 
        $header = [
            'Header' => $header
        ];

        // convert array to xml and add to xml
        $this->arraytoXML($header, $xml);
    }
    function MasterFilesSection(&$xml)
    {
        $master_files_data = array();
        $master_files_data['Customer'] = $this->getCutomersArray();
        $master_files_data['Product'] = $this->getProductsArray();
        $master_files_data['TaxTableEntry'] = $this->getTaxTableEntryArray();

        $master_files_data = [
            'MasterFiles' => $master_files_data
        ];

        // convert array to xml and add to xml
        $this->arraytoXML($master_files_data, $xml);
    }
    function SourceDocumentsSection(&$xml)
    {
        $source_documents_data = array();


        $source_documents_data['SalesInvoices'] = $this->getSaleInvoicesArray();
        $source_documents_data['Payments'] = $this->getPaymentsArray();
        $source_documents_data = [
            'SourceDocuments' => $source_documents_data
        ];

        // convert array to xml and add to xml
        $this->arraytoXML($source_documents_data, $xml);
    }

    /**
     * The function retrieves sale invoices data and stores it in an array.
     */
    function getSaleInvoicesArray(): array
    {
        // get saleinvoices data from our system
        $controller = app()->make(SellController::class);
        $saleinvoices = $controller->saleinvoices();

        $taxes = TaxRate::where('business_id', $this->business->id)->get();

        // $invoices to store saf_t saleinvoices data accoding to saf_t format as array
        $invoices = [];

        // make array of invoices similar to saf-t xml 
        foreach ($saleinvoices as $pkey => $saleinvoice) {

            // total tax for invoice   
            $TaxPayable = 0;

            // calculate order tax
            if (!empty($saleinvoice->tax)) {
                if ($saleinvoice->tax->is_tax_group) {
                    /* ignore the below line because i dont know what is the purpose of this line */
                    // $order_taxes = $this->transactionUtil->sumGroupTaxDetails($this->transactionUtil->groupTaxDetails($saleinvoice->tax, $saleinvoice->tax_amount));
                } else {
                    $TaxPayable += $saleinvoice->tax_amount;
                }
            }
            $invoice = [
                'InvoiceNo' => $saleinvoice->invoice_no,
                'DocumentStatus' => [
                    'InvoiceStatus' => 'N',
                    'InvoiceStatusDate' =>  date('Y-m-d\TH:i:s', strtotime($saleinvoice->activities[0]->created_at)),
                    'SourceID' => $saleinvoice->id,
                    'SourceBilling' => 'P',
                ],
                'Hash' => 'cQNDgkjRDH/5/LNtTVxHnPXf4hLsiiBVCV0B5XPOCj5eTzwbf1hvQT86PHNSNt6Oq2UxvpIrWr1FTDTQwX8m6+Zpae9norJbdMYWsoFuJtn4sSk40Q6ffQbYHZc4cIgz+n+v79De3rNlxn7AaLZbz85NzxC+ILVRmB7r7EEsphI=',
                'HashControl' => '1',
                'Period' => date('m'),
                'InvoiceDate' => date('Y', strtotime($saleinvoice->created_at)),
                'InvoiceType' => "FR",
                'SpecialRegimes' => [
                    'SelfBillingIndicator' => 0,
                    'CashVATSchemeIndicator' => 0,
                    'ThirdPartiesBillingIndicator' => 0,
                ],
                'SourceID' => $saleinvoice->id,
                'SystemEntryDate' =>  date('Y-m-d\TH:i:s', strtotime($saleinvoice->created_at)),
                'CustomerID' => $saleinvoice->contact->id,
            ];

            if (count($saleinvoice['sell_lines']) < 1) {
                continue;
            }

            // item lines that is sold in the invoice 
            foreach ($saleinvoice['sell_lines'] as $key => $line) {
                $TaxPayable += ($line->item_tax * $line->quantity);
                $invoice['Line'][] = [
                    'LineNumber' => $key + 1,
                    'ProductCode' => $line->product->sku,
                    'ProductDescription' => $line->product->name,
                    'Quantity' => $line->quantity,
                    'UnitOfMeasure' => $line->product->unit->short_name,
                    'UnitPrice' => $line->unit_price,
                    'TaxPointDate' => date('Y-m-d', strtotime($line->created_at)),
                    'Description' => $line->product->name,
                    'CreditAmount' => $line->quantity * $line->unit_price_inc_tax,
                ];

                if ($taxes->where('id', $line->tax_id)->count() > 0) {
                    $invoice['Line'][count($invoice['Line']) - 1]['Tax'] = [
                        'TaxType' =>  $taxes->where('id', $line->tax_id)->first()->tax_type,
                        'TaxCountryRegion' => $taxes->where('id', $line->tax_id)->first()->tax_country_region,
                        'TaxCode' =>  $taxes->where('id', $line->tax_id)->first()->tax_code,
                        'ThirdPartiesBillingIndicator' => 0,
                        'TaxPercentage' => $taxes->where('id', $line->tax_id)->first()->amount,
                    ];
                } else {
                    $invoice['Line'][count($invoice['Line']) - 1]['Tax'] = [
                        'TaxExemptionReason' => "Regime Simplificado",
                        'TaxExemptionCode' => "M00",
                    ];
                }
            }

            // GrossTotal is the sum of all item lines (include line tax)
            $creditAmounts = array_column($invoice['Line'], 'CreditAmount');
            $GrossTotal = array_sum($creditAmounts);

            // get payment details 
            [$PaymentAmount, $PaymentMechanism, $PaymentDate] = $this->getPaymentDetails($saleinvoice);

            $invoice['DocumentTotals'] = [
                'TaxPayable' => $TaxPayable,
                'NetTotal' => $GrossTotal - $saleinvoice->discount_amount,
                'GrossTotal' => $GrossTotal,
                'Payment' => [
                    'PaymentMechanism' => $PaymentMechanism,
                    'PaymentAmount' => $PaymentAmount,
                    'PaymentDate' => $PaymentDate,
                ],
            ];
            $invoices[] = $invoice;
        }

        // counting TotalDebit for saft
        $DocumentTotalsArr = array_column($invoices, 'DocumentTotals');
        $NetTotalArr = array_column($DocumentTotalsArr, 'NetTotal');
        $TotalCredit = array_sum($NetTotalArr);

        // Saft SalesInvoices 
        return $invoices = [
            'NumberOfEntries' => count($invoices),
            'TotalDebit' => 0,
            'TotalCredit' => $TotalCredit,
            'Invoice' =>  $invoices,
        ];
    }

    /**
     * The function "getPaymentDetails" retrieves payment details from a sale invoice object in PHP.
     * 
     * @param saleinvoice This is an object that represents a sale invoice. It likely contains information
     * such as the customer, items sold, and payment details.
     * 
     * @return An array containing three values: PaymentAmount, PaymentMechanism, and PaymentDate.
     */

    function getPaymentDetails($saleinvoice)
    {
        $PaymentAmount = 0;
        $PaymentMechanism = '';
        $PaymentDate = '';

        if (count($saleinvoice->payment_lines) > 1) {
            foreach ($saleinvoice->payment_lines as $key => $payment_line) {
                $PaymentAmount += $payment_line->amount;
                if ($key == 0) {
                    $PaymentMechanism = strtoupper($payment_line->method);
                } else if ($PaymentMechanism != strtoupper($payment_line->method)) {
                    $PaymentMechanism = strtoupper("Partial");
                }
            }
            $last_index = count($saleinvoice->payment_lines) - 1;
            $PaymentDate = date('Y-m-d', strtotime($saleinvoice->payment_lines[$last_index]->paid_on));
        } else {
            $PaymentAmount += $saleinvoice->payment_lines[0]->amount;
            $PaymentMechanism = strtoupper($saleinvoice->payment_lines[0]->method);
            $PaymentDate = date('Y-m-d', strtotime($saleinvoice->payment_lines[0]->paid_on));
        }
        return [$PaymentAmount, $PaymentMechanism, $PaymentDate];
    }

    function getPaymentsArray(): array
    {


        $payments = TransactionPayment::leftjoin('transactions as t', 'transaction_payments.transaction_id', '=', 't.id')
            ->where('t.business_id', $this->business->id)
            ->where('t.type', 'sell')
            ->whereNotNull('t.type')

            ->with(['transaction.activities', 'transaction.payment_lines', 'transaction.contact'])
            ->select('transaction_payments.*')
            ->get();


        $S_Payments = [];
        foreach ($payments as $payment) {

            $S_Payment = [
                'PaymentRefNo' => $payment->payment_ref_no,
                'Period' => date('m'),
                'TransactionDate' => $payment->transaction->transaction_date,

                // Hardcode
                'PaymentType' => "RG",

                'SystemID' => $payment->id,
                'DocumentStatus' => [
                    'PaymentStatus' => 'N',
                    'PaymentStatusDate' =>  date('Y-m-d\TH:i:s', strtotime($payment->transaction->activities[0]->created_at)),
                    'SourceID' => $payment->transaction_id,
                    'SourcePayment' => 'P',
                ],
                'PaymentMethod' => [
                    'PaymentMechanism' => strtoupper($payment->method),
                    'PaymentAmount' => (float) $payment->amount,
                    'PaymentDate' => $payment->paid_on,
                ],
                'SourceID' => $payment->transaction_id,
                'SystemEntryDate' =>  date('Y-m-d\TH:i:s', strtotime($payment->created_at)),
                'CustomerID' => $payment->transaction->contact->id,
                'Line' => [
                    [
                        'LineNumber' => 1,
                        'SourceDocumentID' => [
                            'OriginatingON' => $payment->transaction->invoice_no,
                            'InvoiceDate' => $payment->transaction->created_at,
                        ],
                        // Hardcode
                        'DebitAmount' =>  0,
                        'CreditAmount' => (float) $payment->amount,
                    ]

                ]
            ];
            $S_Payments[] = $S_Payment;
        }

        // counting TotalDebit for saft
        $LinesArr = array_column($S_Payments, 'Line');
        $CreditAmountArr = array_column($LinesArr, 'CreditAmount');
        $CreditAmount = array_sum($CreditAmountArr);

        return [
            'NumberOfEntries' => count($S_Payments),
            // Hardcode
            'TotalDebit' => 0,
            'TotalCredit' => $CreditAmount,
            'Payment' => $S_Payments,
        ];
    }

    // parameter pass by reference so no need of return type
    function arraytoXML($json_arr, &$xml)
    {
        foreach ($json_arr as $key => $value) {
            if (is_int($key)) {
                $key = 'Element' . $key;  //To avoid numeric tags like <0></0>
            }

            // to add elements that should be duplicate || second condition is for payments because it was conflicting with sale invoices
            if (in_array($key, ['Line', 'Invoice', 'Customer', 'Product', 'TaxTableEntry'])  || (in_array($key, ['Payment']) &&  is_array($value) && isset($value[0]))) {
                foreach ($value as $line) {
                    $label = $xml->addChild($key);
                    $this->arrayToXml($line, $label);  // Add nested elements.
                }
            } elseif (is_array($value)) {
                $label = $xml->addChild($key);
                $this->arrayToXml($value, $label);  //Add nested elements.
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }

    /**
     * The function retrieves customer data from a database and formats it into an array with SAF-T structure.
     * 
     * @return an array of customers with their details such as ID, tax ID, company name, billing
     * address, email, and self-billing indicator. The customers are filtered based on the business ID
     * and customer group ID, and only active customers are included in the array.
     */
    function getCutomersArray()
    {
        $cutomers = Contact::where('contacts.business_id', $this->business->id)
            ->leftjoin('customer_groups as cg', 'cg.id', '=', 'contacts.customer_group_id')
            ->select('*', 'contacts.*')
            ->active()->onlyCustomers()->get();

        $s_cutomers = [];

        foreach ($cutomers as $cutomer) {

            $s_cutomer = [
                'CustomerID' => $cutomer->id,
                'AccountID' => $cutomer->id,
                'CustomerTaxID' => $cutomer->id,
                'CompanyName' => $this->business->name,
                'BillingAddress' => [
                    'AddressDetail' => $this->concatenateIfBothSet($cutomer->address_line_1, $cutomer->address_line_1),
                    'City' => isset($cutomer->city) && $cutomer->city  ?  $cutomer->city : " ",
                    'PostalCode' => ' ',
                    'Country' => isset($cutomer->country) && $cutomer->country  ?  getCountryPrefix($cutomer->country) : " ",
                ],
                'Email' => isset($cutomer->email) && $cutomer->email ? $cutomer->email : " ",
                'SelfBillingIndicator' => 0,
            ];
            $s_cutomers[] = $s_cutomer;
        }
        return $s_cutomers;
    }

    function concatenateIfBothSet($var1, $var2)
    {
        if (isset($var1) && $var1 && isset($var2) && $var2) {
            $result = $var1 . $var2;
            return $result;
        } elseif (isset($var1) && $var1) {
            return $var1;
        } elseif (isset($var2) && $var2) {
            return $var2;
        } else {
            return " ";
        }
    }

    /**
     * The function retrieves an array of products belonging to a SAF-T structure.
     * 
     * @return an array of products with their details such as product type, product code, product
     * description, and product number code.
     */
    function getProductsArray()
    {
        $products = Product::where('products.business_id', $this->business->id)->get();
        $s_products = [];

        foreach ($products as $product) {
            $s_product = [
                'ProductType' => 'P',
                'ProductCode' => $product->sku,
                'ProductDescription' => $product->name . (isset($product->product_description) && $product->product_description ? $product->product_description : ' '),
                'ProductNumberCode' => $product->id,
            ];

            $s_products[] = $s_product;
        }
        return $s_products;
    }


    /**
     * The function retrieves tax information from a database and returns it as an array related to saf-t.
     * 
     * @return An array of tax table entries, where each entry contains information about a tax rate such
     * as tax type, country/region, tax code, description, and tax percentage.
     */
    function getTaxTableEntryArray()
    {
        $taxes = TaxRate::where('business_id', $this->business->id)->get();
        $s_taxes = [];

        foreach ($taxes as $tax) {
            $s_tax = [
                'TaxType' => $tax->tax_type,
                'TaxCountryRegion' => $tax->tax_country_region,
                'TaxCode' => $tax->tax_code,
                'Description' => $tax->name,
                'TaxPercentage' => $tax->amount,
            ];

            $s_taxes[] = $s_tax;
        }

        return $s_taxes;
    }
}
