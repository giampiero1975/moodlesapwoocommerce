<?php
header('Content-Type: text/plain');
if (!class_exists('SoapClient')) {
    die ("You haven't installed the PHP-Soap module.");
}

$string='<BOM>
                                <BO><AdmInfo>
                                <requestUser>manager</requestUser>
                                <requestDataTime>2023-03-01 01:03:06</requestDataTime>
                                <requestDB>METMI_TEST</requestDB>
                                <Object>13</Object>
                                <Version>2</Version>
                            </AdmInfo><Documents>
                                <row>
                                    <Series>94</Series>
                                    <DocNum>2023200007</DocNum>
                                    <CardCode>CM002735</CardCode>
                                    <ShipToCode>PIETRO PELLIZZARI</ShipToCode>
                                    <DocDate>20230301</DocDate>
                                    <TaxDate>20230301</TaxDate>
                                    <DocCurrency>EUR</DocCurrency>
                                    <PaymentMethod>CBI</PaymentMethod>
                                    <PaymentGroupCode>54</PaymentGroupCode>
                                    <DocTotal>222.00</DocTotal>
                                    <U_B1SYS_INV_TYPE>TD01</U_B1SYS_INV_TYPE>
                                </row>
                            </Documents><Document_Lines><row>
                                    <ItemCode>MEcdATI14 2023</ItemCode>
				                    <ItemDescription>Corso ECM ATI14 anno 2023 - PIETRO PELLIZZARI</ItemDescription>
				                    <Quantity>1</Quantity>
				                    <LineTotal>220.00</LineTotal>
				                    <VatGroup>Ves10n20</VatGroup>
				                    <AccountCode>47006050040</AccountCode>
                                </row><row>
				                        <ItemCode>MEbollo02</ItemCode>
				                        <ItemDescription>Marca da bollo</ItemDescription>
				                        <Quantity>1</Quantity>
				                        <LineTotal>2.00</LineTotal>
				                        <VatGroup>Vesc15</VatGroup>
				                        <AccountCode>47006050999</AccountCode>
			                         </row></Document_Lines><Document_Installments>
                                <row>
                                    <DueDate>20230301</DueDate>
                                    <Total>222.00</Total>
                                </row>
                            </Document_Installments></BO>
                                </BOM>
';
#ini_set('max_execution_time', 1);
try {
    $options = array(
        'soap_version' => SOAP_1_2,
        'exceptions'   => true,
        'trace'        => 1,
        'cache_wsdl'   => WSDL_CACHE_NONE
    );
    $client = new SoapClient('http://192.168.10.44/wsToSAP/B1Sync.asmx', $options);
    $client->BOsync('set','documenti',$string);
    // Note where 'CreateIncident' and 'request' tags are in the XML
    /*
    $results = $client->CreateIncident(
        array(
            'FirstName'         => 'gyaan',
            'LastName'          => 'p',
            'Email'             => 'aa@gmail.com',
            'QueryProductClass' => 'QueryProductClass',
            'ChannelCode'       => 12,
            'CampaignCode'      => 234,
            'Lob'               => 'Lob',
            'PackageName'       => 'SEONI',
            'PackageCode'       => 'SMP',
            'TravelYear'        => 2012,
            'TravelMonth'       => 06,
            'TravelDay'         => 29,
            'CityOfResidence'   => 'Jabalpur',
            'ncidentNotes'      => 'testing ignor this',
            'MobilePhone'       => '1234567890',
            'DepartureCity'     => 'bangalore',
            'NoOfDaysTravel'    => '3 Days',
            'VendorName'        => 'TEST HIQ'
        )
    );
      */
}
catch (Exception $e) {
    echo "<h2>Exception Error!</h2>";
    echo $e->getMessage();
}
?>