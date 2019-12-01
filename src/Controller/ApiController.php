<?php

namespace App\Controller;

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends AbstractController
{
    /**
     * @Route("/api", name="api")
     */
    public function index(Request $request)
    {

        //citizenship_country=XX&birth_date=dd/mm/YYYY&passport_number=XXXXX&passport_delivery_date=dd/mm/YYYY&passport_expiration_date=dd/mm/YYYY&lang=XX

        $country = $request->query->get('citizenship_country', 'FR');
        $birthDate = $request->query->get('birth_date', '13/12/1984');
        $passport = $request->query->get('passport_number', '19AA04043');
        $deliveryDate = $request->query->get('passport_delivery_date', '16/01/2019');
        $expirationDate = $request->query->get('passport_expiration_date', '15/01/2029');
        $lang = $request->query->get('lang','fr');


        //- Autorisation accepté : - Citoyenneté : FR, date de naissance : , passeport : 19AA04043, date délivrance passeport : 16/01/2019, date expiration passeport : 15/01/2029
        //- Autorisation en attente : - Citoyenneté : FR, date de naissance : 01/03/1959, passeport : 18CK29779, date délivrance passeport 17/04/2018: , date expiration passeport : 16/06/2025
        //- Autorisation refusée : - Citoyenneté : FR, date de naissance : 04/11/1948, passeport : 11CI61564, date délivrance passeport : 25/07/2011, date expiration passeport : 24/07/2021

        // flibus.team/api/?citizenship_country=FR&birth_date=01/03/1959&passport_number=18CK29779&passport_delivery_date=17/04/2018&passport_expiration_date=16/06/2025
        // flibus.team/api/?citizenship_country=FR&birth_date=04/11/1948&passport_number=11CI61564&passport_delivery_date=25/07/2011&passport_expiration_date=24/07/2021

        try {

            $puppeteer = new Puppeteer();
            $browser = $puppeteer->launch(['args' => ['--no-sandbox']]);

            $page = $browser->newPage();
            $page->goto('https://esta.cbp.dhs.gov');
            $page->click('#dropdownMain2');
            $page->click('#check_link > ul:nth-child(2) > li:nth-child(1)');
            //$page->click('#confirmBtn');
            $page->waitFor('#confirmBtn', ['visible' => true  ]);
            $page->click('#confirmBtn');
            $page->waitFor('#passportNum');

            $page->focus('#passportNum');
            $page->keyboard->type($passport);

            $this->setField($page, '#day_birthday', (int) explode('/', $birthDate)[0]);
            $this->setField($page, '#month_birthday', (int) explode('/', $birthDate)[1]);
            $this->setField($page, '#year_birthday', (int) explode('/', $birthDate)[2]);

            $this->setField($page, '#citizenshipCountry', $country );

            $this->setField($page, '#day_issueDate', (int) explode('/', $deliveryDate)[0]);
            $this->setField($page, '#month_issueDate', (int) explode('/', $deliveryDate)[1]);
            $this->setField($page, '#year_issueDate', (int) explode('/', $deliveryDate)[2]);

            $this->setField($page, '#day_expDate', (int) explode('/', $expirationDate)[0]);
            $this->setField($page, '#month_expDate', (int) explode('/', $expirationDate)[1]);
            $this->setField($page, '#year_expDate', (int) explode('/', $expirationDate)[2]);

            $page->waitFor('button.btn:nth-child(9)');
            $page->click('button.btn:nth-child(9)');

            $page->waitFor('#header > div > h1:nth-child(1)');
            $h1 = $page->querySelector('#header > div > h1:nth-child(1)');


            $h1 = $page->evaluate(JsFunction::createWithBody("
                const element = document.querySelector('#header > div > h1:nth-child(1)' );
                return element.innerHTML;
    
            "));



            $approved = 'AUTHORIZATION APPROVED';
            $pending = 'AUTHORIZATION PENDING';
            $cancel = 'TRAVEL NOT AUTHORIZED';

            switch($h1) {
                case $approved :
                    $status = 'approved';

                break;

                case $pending :
                    $status = 'pending';

                break;

                case $cancel :
                    $status = 'cancel';

                break;

                default :
                    $status = 'unknown';
                break;

            }


            //application number.
            $s1 = '#appInfoTable > tbody > tr > td:nth-child(3) > span';
            $applicationNumber = $this->innerHTML($page, $s1);


            //expiration date.
            $s2 = '#appInfoTable > tbody > tr > td:nth-child(6) > span';
            $expirationDate = $this->innerHTML( $page, $s2 );

            $page->hover('#languageDrop');
            $page->click('#lang-'. $lang );

            sleep(1);

            $page->waitFor('#appInfoTable > tbody > tr > td:nth-child(8) > a');
            $page->click('#appInfoTable > tbody > tr > td:nth-child(8) > a');

            sleep(4);

            $pages = $browser->pages();

            //$pages[count($pages) -1]->waitFor('body > app-root > div > div:nth-child(3) > app-print > div > app-print-app > div:nth-child(1) > div > h1');


            $rand = md5(time());
            $file = __DIR__.'/../../public/data/'.$rand.'.pdf';

            $pages[count($pages) -1]->pdf(['path' => $file]);


            $ret = [
                'status'                => $status,
                'application_number'    => $applicationNumber,
                'expiration_date'       => $this->convertExpirationDate($expirationDate),
                'pdf'                   => sprintf('%s/data/%s.pdf', $_ENV['HOST'],$rand),
            ];

            return new JsonResponse($ret);

            return $this->json($ret);

        } catch(\Exception $e ) {

            return $this->json([
                'status' => 'error',
                'msg' => $e->getMessage(),
            ]);
        }
    }

    private function setField( $page, $selector, $value ) {
        $page->evaluate(JsFunction::createWithBody("
            const element = document.querySelector( selector );
            element.value = value;
            var event = new Event('change', { bubbles: true });
            event.simulated=true;
            element.dispatchEvent(event);
        ")->scope(['selector' => $selector, 'value' => $value]));

    }

    private function innerHTML($page, $selector ) {
        return  $page->evaluate(JsFunction::createWithBody("
            const element = document.querySelector(selector );
            return element.innerHTML;

        ")->scope(['selector' => $selector]));

    }

    private function convertExpirationDate( $date ) {
        if(preg_match('/([a-z]*) ([0-9]*), ([0-9]*)/i', $date, $match ) ) { //'Nov 29, 2021
            $month = $match[1];
            $day = $match[2];
            $year = $match[3];

            switch ($month) {
                case 'Jan':
                    $month = '01';
                    break;
                case 'Feb':
                    $month = '02';
                    break;
                case 'Mar':
                    $month = '03';
                case 'Apr':
                    $month = '04';
                    break;
                case 'May':
                    $month = '05';
                    break;

                case 'Jun':
                    $month = '06';
                    break;

                case 'Jul':
                    $month = '07';
                    break;

                case 'Aug':
                    $month = '08';
                    break;

                case 'Sept':
                    $month = '09';
                    break;

                case 'Oct':
                    $month = '10';
                    break;

                case 'Nov':
                    $month = '11';
                    break;

                case 'Dec':
                    $month = '12';
                    break;

                default:
                    $month = 'undefined';
                    break;


            }

            return sprintf('%s/%s/%s', $day, $month, $year );
        } else {
            return $date;
        }
    }
}


/*
(async () => {
        const object  = await factory.getBrowser();
        const browser = object.browser;
        const page = await browser.newPage();
        await page.goto('https://esta.cbp.dhs.gov', { timeout : 0 });

        await page.hover('#languageDrop');

        await page.$eval('#lang-' + lang.toLowerCase(), elem => elem.click());

        page.waitForSelector('#dropdownMain2', { visible : true }).then(() => {
            page.$eval('#dropdownMain2', elem => elem.click()).then( async () => {
                await page.waitForSelector('#modalTitle');

                page.waitForSelector('#check_link > ul:nth-child(2) > li:nth-child(1)', {visible:true}).then(() => {
                    page.$eval('#check_link > ul:nth-child(2) > li:nth-child(1)', elem => elem.click() ).then(() => {
                        page.waitForSelector('#confirmBtn', { visible: true }).then(() => {
                            page.$eval('#confirmBtn', elem => elem.click()).then(async () => {

                                const setValue = async ( selector, value  ) => {
                                    await page.evaluate ((selector , value ) => {
                                        const element = document.querySelector( selector );
                                        element.value = value;
                                        var event = new Event('change', { bubbles: true });
                                        event.simulated=true;
                                        element.dispatchEvent(event);
                                    }, selector , value );
                                };

                                try {

                                    await page.waitFor('#passportNum');

                                    await page.focus('#passportNum');
                                    await page.keyboard.type(passNumber);

                                    await setValue('#day_birthday', parseInt(birthdate.split('/')[0]));
                                    await setValue('#month_birthday', parseInt(birthdate.split('/')[1]));
                                    await setValue('#year_birthday', parseInt(birthdate.split('/')[2]));

                                    await setValue('#citizenshipCountry', country);

                                    await setValue('#day_issueDate', parseInt(inssuanceDate.split('/')[0]));
                                    await setValue('#month_issueDate', parseInt(inssuanceDate.split('/')[1]));
                                    await setValue('#year_issueDate', parseInt(inssuanceDate.split('/')[2]));

                                    await setValue('#day_expDate', parseInt(expirationDate.split('/')[0]));
                                    await setValue('#month_expDate', parseInt(expirationDate.split('/')[1]));
                                    await setValue('#year_expDate', parseInt(expirationDate.split('/')[2]));

                                    await page.waitForSelector('button.btn:nth-child(9)');
                                    await page.click('button.btn:nth-child(9)');

                                    page.waitForSelector('body > app-root > div > div:nth-child(3) > app-individual-status-lookup > div > div.alert.alert-danger', {timeout: 5000}).then(async () => {
                                        res.send("Ce compte n'existe pas");
                                        await factory.close(object);
                                        //await browser.close();
                                    }, async (error) => {
                                        console.log('error');
                                        await page.waitForSelector('body > app-root > div > div:nth-child(3) > app-esta-status > div > div > app-print-download > div > a:nth-child(2)');
                                        N = await browser.pages();
                                        count = N.length;
                                        await page.click('body > app-root > div > div:nth-child(3) > app-esta-status > div > div > app-print-download > div > a:nth-child(2)');
                                        setTimeout(async () => {
                                            let pages = await browser.pages();
                                            //let count = pages.length;
                                            await pages[count].waitForSelector('body > app-root > div > div:nth-child(3) > app-print > div > app-esta-status > div > div > div:nth-child(4) > div:nth-child(1) > div.col-xs-12.col-sm-6 > h2')
                                                .then(async () => {
                                                    //const buffer = await pages[2].pdf({path : 'file.pdf', format: 'A4'});
                                                    res.writeHead(200, {'Content-Type': 'text/html'});
                                                    const data = await pages[count].evaluate(() => document.body.innerHTML);
                                                    res.end(await pages[count].content());
                                                    //res.end(buffer, 'binary');
                                                    await factory.close(object);

                                                }, (error) => {
                                                    console.log(error);
                                                });

                                        }, 3000);
                                    });
                                } catch( error ) {
                                    console.log( 'exception', error );
                                    await factory.close( object );
                                    throw error;
                                }
                            })
                        });
                    });
                }).catch((err) => {
                    page.click('#check_link > ul:nth-child(2) > li:nth-child(1)').then(() => {
                        console.log('catch click done');
                    });
                });


            })
        });


    })();


*/
