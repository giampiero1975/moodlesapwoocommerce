<?php
// inserimento fattura

$xml_data = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
                                <soapenv:Header/>
                                    <soapenv:Body>
                                    <loc:BOsync>
                                    <loc:reqType>set</loc:reqType>
                                    <loc:objType>documenti</loc:objType>
                                    <loc:docXml>&lt;BOM&gt;
                                &lt;BO&gt;&lt;AdmInfo&gt;
                                &lt;requestUser&gt;manager&lt;/requestUser&gt;
                                &lt;requestDataTime&gt;2023-06-22 03:06:59&lt;/requestDataTime&gt;
                                &lt;requestDB&gt;METMI_LIVE&lt;/requestDB&gt;
                                &lt;Object&gt;13&lt;/Object&gt;
                                &lt;Version&gt;2&lt;/Version&gt;
                            &lt;/AdmInfo&gt;&lt;Documents&gt;
                                &lt;row&gt;
                                    &lt;Series&gt;94&lt;/Series&gt;
                                    &lt;DocNum&gt;2023200003&lt;/DocNum&gt;
                                    &lt;CardCode&gt;CM003979&lt;/CardCode&gt;
                                    &lt;ShipToCode&gt;Frau Simona&lt;/ShipToCode&gt;
                                    &lt;DocDate&gt;20230622&lt;/DocDate&gt;
                                    &lt;TaxDate&gt;20230622&lt;/TaxDate&gt;
                                    &lt;DocCurrency&gt;EUR&lt;/DocCurrency&gt;
                                    &lt;PaymentMethod&gt;Cl.Bon.Bancario&lt;/PaymentMethod&gt;
                                    &lt;PaymentGroupCode&gt;54&lt;/PaymentGroupCode&gt;
                                    &lt;DocTotal&gt;20.00&lt;/DocTotal&gt;
                                    &lt;U_B1SYS_INV_TYPE&gt;TD01&lt;/U_B1SYS_INV_TYPE&gt;
                                &lt;/row&gt;
                            &lt;/Documents&gt;&lt;Document_Lines&gt;&lt;row&gt;
                                    &lt;ItemCode&gt;MEcdIDT2023&lt;/ItemCode&gt;
				                    &lt;ItemDescription&gt;Corso ECM Infermiere di Territorio - Frau Simona&lt;/ItemDescription&gt;
				                    &lt;Quantity&gt;1&lt;/Quantity&gt;
				                    &lt;LineTotal&gt;20.00&lt;/LineTotal&gt;
				                    &lt;VatGroup&gt;Ves10n20&lt;/VatGroup&gt;
				                    &lt;AccountCode&gt;47006050094&lt;/AccountCode&gt;
                                &lt;/row&gt;&lt;/Document_Lines&gt;&lt;Document_Installments&gt;
                                &lt;row&gt;
                                    &lt;DueDate&gt;20230622&lt;/DueDate&gt;
                                    &lt;Total&gt;20.00&lt;/Total&gt;
                                &lt;/row&gt;
                            &lt;/Document_Installments&gt;&lt;/BO&gt;
                                &lt;/BOM&gt;</loc:docXml>
                              </loc:BOsync>
                              </soapenv:Body>
                              </soapenv:Envelope>';

$headers = array(
    "POST /wsToSAP/B1Sync.asmx HTTP/1.1",
    "Referer: 192.168.10.44",
    //"User-Agent: Dalvik/1.6.0 (Linux; U; Android 4.4.2; GT-I9505 Build/KOT49H)",
    "Content-Type: text/xml; charset=utf-8",
    "Host: 192.168.10.44",
    "Content-length: ".strlen($xml_data),
    "Expect: 100-continue"
);

$url = 'http://192.168.10.44/wsToSAP/B1Sync.asmx?reqType=get&objType=documenti';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,$url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_data);

$reply = curl_exec($ch);

echo($reply);
?>
