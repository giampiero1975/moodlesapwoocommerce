<?php
//Data, connection, auth
$soapUrl = "http://192.168.10.44/wsToSAP/B1Sync.asmx?reqType=set&objType=primenote"; // asmx URL of WSDL
$soapUser = "manager";  //  username
$soapPassword = "manage.1"; // password

// xml post structure
$xml_post_string ='<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
                                <soapenv:Header/>
                                    <soapenv:Body>
                                    <loc:BOsync>
                                    <loc:reqType>set</loc:reqType>
                                    <loc:objType></loc:objType>
                                    <loc:docXml>&lt;BusinessPartner&gt;&lt;RequestInfo&gt;
                                    &lt;requestUser&gt;manager&lt;/requestUser&gt;
                                    &lt;requestDataTime&gt;2023-06-27 10:06:15&lt;/requestDataTime&gt;
                                    &lt;requestDB&gt;METMI_TEST&lt;/requestDB&gt;
                                &lt;/RequestInfo&gt;&lt;Data&gt;
                                &lt;codice&gt;CM003981&lt;/codice&gt;
                                &lt;ragsoc&gt;Levinta Daniela&lt;/ragsoc&gt;
                                &lt;conto&gt;14001010&lt;/conto&gt;
                                &lt;tiposoll&gt;&lt;/tiposoll&gt;
                                &lt;tipoimp&gt;&lt;/tipoimp&gt;
                                &lt;codiva&gt;&lt;/codiva&gt;
                                &lt;cat&gt;104&lt;/cat&gt;
                                &lt;zona&gt;&lt;/zona&gt;
                                &lt;valuta&gt;EUR&lt;/valuta&gt;
                                &lt;codage&gt;-1&lt;/codage&gt;
                                &lt;piva&gt;IT04384040244&lt;/piva&gt;
                                &lt;codfis&gt;LVNDNL98D49Z140O &lt;/codfis&gt;
                                &lt;singlepay&gt;N&lt;/singlepay&gt;
                                &lt;tel1&gt;3662435108&lt;/tel1&gt;
                                &lt;tel2&gt;&lt;/tel2&gt;
                                &lt;fax&gt;&lt;/fax&gt;
                                &lt;email&gt;daniela.levinta@pec.opivicenza.it&lt;/email&gt;
                                &lt;web&gt;&lt;/web&gt;
                                &lt;lingua&gt;13&lt;/lingua&gt;
                                &lt;tipobp&gt;CBI&lt;/tipobp&gt;
                                &lt;bloccopag&gt;N&lt;/bloccopag&gt;
                                &lt;numlettes&gt;&lt;/numlettes&gt;
                                &lt;impes&gt;&lt;/impes&gt;
                                &lt;dtiniese&gt;&lt;/dtiniese&gt;
                                &lt;dtfinese&gt;&lt;/dtfinese&gt;
                                &lt;impfido&gt;&lt;/impfido&gt;
                                &lt;annullato&gt;N&lt;/annullato&gt;
                                &lt;noteana&gt;&lt;/noteana&gt;
                                &lt;abiint&gt;08374&lt;/abiint&gt;
                                &lt;nazint&gt;IT&lt;/nazint&gt;
                                &lt;cabint&gt;32480&lt;/cabint&gt;
                                &lt;ccint&gt;000000113266&lt;/ccint&gt;
                                &lt;codpag&gt;&lt;/codpag&gt;
                                &lt;sapproperty&gt;&lt;/sapproperty&gt;
                                &lt;intra&gt;N&lt;/intra&gt;
                                &lt;tipoes&gt;I&lt;/tipoes&gt;
                                &lt;indirpec&gt;&lt;/indirpec&gt;
                                &lt;coddestsdi&gt;0000000&lt;/coddestsdi&gt;
                                &lt;CheckPA&gt;N&lt;/CheckPA&gt;
                                &lt;codop347&gt;&lt;/codop347&gt;
                                &lt;opassic347&gt;N&lt;/opassic347&gt;
                                &lt;ritacc&gt;N&lt;/ritacc&gt;
                                &lt;Conguaglio&gt;N&lt;/Conguaglio&gt;
                            	&lt;settore&gt;-1&lt;/settore&gt;
                                &lt;userfield&gt;&lt;/userfield&gt;
                                &lt;indirizzi&gt;
                                  &lt;indirizzo&gt;
                                    &lt;tipoind&gt;B&lt;/tipoind&gt;
                                    &lt;idind&gt;Levinta Daniela&lt;/idind&gt;
                                    &lt;viaind&gt;VIA PAOLO BOSELLI 38&lt;/viaind&gt;
                                    &lt;capind&gt;36100&lt;/capind&gt;
                                    &lt;locind&gt;VICENZA&lt;/locind&gt;
                                    &lt;statind&gt;IT&lt;/statind&gt;
                                    &lt;provind&gt;VI&lt;/provind&gt;
                                    &lt;pivaind&gt;IT04384040244&lt;/pivaind&gt;
                                  &lt;/indirizzo&gt;
                                  &lt;indirizzo&gt;
                                    &lt;tipoind&gt;S&lt;/tipoind&gt;
                                    &lt;idind&gt;Levinta Daniela&lt;/idind&gt;
                                    &lt;viaind&gt;VIA PAOLO BOSELLI 38&lt;/viaind&gt;
                                    &lt;capind&gt;36100&lt;/capind&gt;
                                    &lt;locind&gt;VICENZA&lt;/locind&gt;
                                    &lt;statind&gt;IT&lt;/statind&gt;
                                    &lt;provind&gt;VI&lt;/provind&gt;
                                    &lt;pivaind&gt;&lt;/pivaind&gt;
                                  &lt;/indirizzo&gt;
                                &lt;/indirizzi&gt;
                        &lt;/Data&gt;&lt;/BusinessPartner&gt;</loc:docXml>
                              </loc:BOsync>
                              </soapenv:Body>
                              </soapenv:Envelope>';

$headers = array(
    "Content-type: text/xml;charset=\"utf-8\"",
    "Accept: text/xml",
    "Cache-Control: no-cache",
    "Pragma: no-cache",
    "SOAPAction: http://localhost/BOsync",
    "Content-length: ".strlen($xml_post_string),
); //SOAPAction: your op URL

$url = $soapUrl;

// PHP cURL  for https connection with auth
$ch = curl_init();
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $soapUser.":".$soapPassword); // username and password - declared at the top of the doc
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// converting
$response = curl_exec($ch);
curl_close($ch);

// converting
// echo $response;

// echo htmlspecialchars($response);
echo $response;
die();
$response1 = str_replace("<soap:Body>","",$response);
$response2 = str_replace("</soap:Body>","",$response1);

// convertingc to XML
$parser = simplexml_load_string($response2);
// user $parser to get your data out of XML response and to display it.

#echo $parser;
?>