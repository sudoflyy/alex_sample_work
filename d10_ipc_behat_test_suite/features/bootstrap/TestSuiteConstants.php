<?php

namespace behat\features\bootstrap;
/**
 * Global constants for the test suite.
 */
class TestSuiteConstants
{
  public const IMPLICIT_WAIT = 10000; // milliseconds
  public const WAIT_TIME_SHORTEST = 2;
  public const WAIT_TIME_SHORTER = 5;
  public const WAIT_TIME_SHORT = 10;
  public const WAIT_TIME_MED_LO = 15;
  public const WAIT_TIME_MED = 20;
  public const WAIT_TIME_MED_HI = 25;
  public const WAIT_TIME_LONG = 30;
  public const JS_WAIT_FOR_AJAX = 30;

  public const LOCAL_NATIVE_URL = 'http://ipcedtr.native';
  public const LOCAL_LANDO_URL = 'http://ipcedge.lndo.site';
  public const GITLAB_URL = 'http://127.0.0.1:8080';
  public const DEV_SITE_URL = 'https://ipcedge-dev.ipcinternal.org';
  public const STG_SITE_URL = 'https://ipcedge-stg.ipcinternal.org';
  public const STBY_SITE_URL = 'https://ipcedge-stby.ipcinternal.org';
  public const PROD_SITE_URL = 'https://education.ipc.org';
  public const ECOM_PROD_URL = 'https://shop.ipc.org';
  public const CMS_PROD_URL = 'https://www.ipc.org';
  public const DG_2044_URL = 'https://dg-2044.ipcedge-branches.ipcinternal.org';

  public const BEHAT_FLAG = 'BEHAT-TEST';
  public const MOD_SUFFIX = '-MOD';
  public const GLOBAL_PREFIX = 'ATS-';
  public const GLOBAL_DND_PREFIX = 'DND-';

  public const NON_LATIN_CHAR = 'Д';

  public const TEST_PRODUCT_1_PRODUCT_NUMBER = 'A610-EDG-0-0-0-0-0';
  public const TEST_PRODUCT_1_COURSE_TITLE = 'IPC-A-610 for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_1_PATH = '/product/ipc-610-operators';
  public const TEST_PRODUCT_1_COURSE_TITLE_SHORT = 'IPC-A-610 for Operators';
  public const TEST_PRODUCT_1_CART_ITEM_TITLE = 'IPC-A-610 FOR OPERATORS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_1_LANG = 'English';
  public const TEST_PRODUCT_1_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_1_VOUCHER_TITLE = 'IPC-A-610 for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_1_COURSE_LIST_TITLE = 'IPC-A-610 for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_1_PRE_REQ = 'Electronics Assembly for Operators';

  public const TEST_PRODUCT_2_PRODUCT_NUMBER = 'WHA-EDG-0-0-O-0-0';
  public const TEST_PRODUCT_2_COURSE_TITLE = 'Wire Harness Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_2_PATH = '/product/wire-harness-assembly-operators';
  public const TEST_PRODUCT_2_COURSE_TITLE_SHORT = 'Wire Harness Assembly for Operators';
  public const TEST_PRODUCT_2_CART_ITEM_TITLE = 'WIRE HARNESS ASSEMBLY FOR OPERATORS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_2_LANG = 'English';
  public const TEST_PRODUCT_2_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_2_VOUCHER_TITLE = 'Wire Harness Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_2_COURSE_LIST_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';

  public const TEST_PRODUCT_3_PRODUCT_NUMBER = 'EAE-EDG-0-0-0-0-0';
  public const TEST_PRODUCT_3_COURSE_TITLE = 'Electronics Assembly for Engineers - English, Online Self-paced';
  public const TEST_PRODUCT_3_PATH = '/product/electronics-assembly-engineers';
  public const TEST_PRODUCT_3_COURSE_TITLE_SHORT = 'Electronics Assembly for Engineers';
  public const TEST_PRODUCT_3_CART_ITEM_TITLE = 'ELECTRONICS ASSEMBLY FOR ENGINEERS';
  public const TEST_PRODUCT_3_LANG = 'English';
  public const TEST_PRODUCT_3_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_3_VOUCHER_TITLE = 'Electronics Assembly for Engineers - English, Online Self-paced';
  public const TEST_PRODUCT_3_COURSE_LIST_TITLE = 'Electronics Assembly for Engineers - English, Online Self-paced';

  public const TEST_PRODUCT_4_PRODUCT_NUMBER = 'AOT-EDG-0-0-0-0-0';
  public const TEST_PRODUCT_4_COURSE_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_4_PATH = '/product/electronics-assembly-operators';
  public const TEST_PRODUCT_4_COURSE_TITLE_SHORT = 'Electronics Assembly for Operators';
  public const TEST_PRODUCT_4_CART_ITEM_TITLE = 'ELECTRONICS ASSEMBLY FOR OPERATORS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_4_LANG = 'English';
  public const TEST_PRODUCT_4_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_4_VOUCHER_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_4_COURSE_LIST_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';

  public const TEST_PRODUCT_5_PRODUCT_NUMBER = 'PCB-EDG-0-0-MFG-0-0';
  public const TEST_PRODUCT_5_COURSE_TITLE = 'PCB Design for Manufacturability - English, Online Instructor-led';
  public const TEST_PRODUCT_5_PATH = '/product/pcb-design-manufacturability';
  public const TEST_PRODUCT_5_COURSE_TITLE_SHORT = 'PCB Design for Manufacturability';
  public const TEST_PRODUCT_5_CART_ITEM_TITLE = 'PCB DESIGN FOR MANUFACTURABILITY - ENGLISH, ONLINE INSTRUCTOR-LED';
  public const TEST_PRODUCT_5_LANG = 'English';
  public const TEST_PRODUCT_5_MODALITY = 'Online Instructor-led';
  public const TEST_PRODUCT_5_VOUCHER_TITLE = 'PCB Design for Manufacturability - English, Online Instructor-led, Design for Manufacturability';
  public const TEST_PRODUCT_5_COURSE_LIST_TITLE = 'PCB Design for Manufacturability - English, Online Instructor-led, Design for Manufacturability';

  public const TEST_PRODUCT_6_PRODUCT_NUMBER = 'MDA-EDG-0-0-I-EN-0';
  public const TEST_PRODUCT_6_COURSE_TITLE = 'English, Online Instructor-led, AI Applications of Machine Data in the EMS Industry 102924-103124';
  public const TEST_PRODUCT_6_PATH = '/product/ai-applications-machine-data-ems-industry';
  public const TEST_PRODUCT_6_COURSE_TITLE_SHORT = 'AI Applications of Machine Data in the EMS Industry';
  public const TEST_PRODUCT_6_CART_ITEM_TITLE = 'AI APPLICATIONS OF MACHINE DATA IN THE EMS INDUSTRY - ENGLISH, ONLINE INSTRUCTOR-LED, AI APPLICATIONS OF MACHINE DATA IN THE EMS INDUSTRY 102924-103124';
  public const TEST_PRODUCT_6_LANG = 'English';
  public const TEST_PRODUCT_6_MODALITY = 'Online Instructor-led';
  public const TEST_PRODUCT_6_VOUCHER_TITLE = 'English, Online Instructor-led, AI Applications of Machine Data in the EMS Industry 102924-103124';
  public const TEST_PRODUCT_6_COURSE_LIST_TITLE = '';
  public const TEST_PRODUCT_6_DESCRIPTION = 'Modern equipment inside of an EMS factory generates large volumes of structured data about its performance, status and operations.  This data, when collected and analyzed, can be used to improve factory processes, solve product quality issues and determine the root causes of machine failures. In this course, we will review the data produced by factory machines such as SMT and test equipment and how AI tools can be leveraged to assist with the analysis and interpretation of the data. The course is designed around practical, interactive learning experiences with an anonymized real-world dataset and freely available analysis and visualization tools.  AI agents and copilots will be directly created live in the course using UI tools to visually illustrate the concepts and show what is possible with current technologies. Topics include: What is machine data How to collect and analyze machine data Visualizing and analyzing machine data using Grafana What are AI copilots and agents? Applications of AI technologies to analyzing machine data IPC-CFX standard Taught by an industry expert in the field of machine data analytics, this focused one-week program utilizes interactive webinars and job-specific exercises to facilitate mastery of the key techniques and concepts in machine data analytics.';
  public const TEST_PRODUCT_6_QUANTITY = '1';

  public const TEST_PRODUCT_7_PRODUCT_NUMBER = 'PCB-EDG-0-0-ADC-0-0';
  public const TEST_PRODUCT_7_COURSE_TITLE = 'Advanced Design Concepts - English, Online Instructor-led, PCB Advanced Design Concepts 040825-052925';
  public const TEST_PRODUCT_7_PATH = '/product/advanced-design-concepts';
  public const TEST_PRODUCT_7_COURSE_TITLE_SHORT = 'Advanced Design Concepts';
  public const TEST_PRODUCT_7_CART_ITEM_TITLE = 'ADVANCED DESIGN CONCEPTS - ENGLISH, ONLINE INSTRUCTOR-LED, PCB ADVANCED DESIGN CONCEPTS 040825-052925';
  public const TEST_PRODUCT_7_LANG = 'English';
  public const TEST_PRODUCT_7_MODALITY = 'Online Instructor-led';
  public const TEST_PRODUCT_7_VOUCHER_TITLE = 'Advanced Design Concepts - English, Online Instructor-led, PCB Advanced Design Concepts 040825-052925';
  public const TEST_PRODUCT_7_COURSE_LIST_TITLE = '';
  public const TEST_PRODUCT_7_DESCRIPTION = 'The course will start with design of HDI and advanced packaging concepts. This will be followed by embedded component design and the students will see how concepts from HDI are used in the implementation of embedded components. Next, concepts necessary for the design of wearable electronics and how the use of concepts from HDI and Embedded are necessary to achieve the small size and light weight of wearable electronics.  The course covers the skills necessary to create IPC-compliant PCB designs with: Advanced or complex packaging Reduced available board area Non-orthogonal placement and routing Non-standard board outline geometry Non-standard board mounting Advanced board materials Embedded components Cavities to reduce overall volume/skyline of the design Human interface/wearable technology Advanced Design Concepts is accredited by the ANSI National Accreditation Board under ANSI/ASTM E2659-18, Standard Practice for Certificate Programs. The referenced media source is missing and needs to be re-embedded.';
  public const TEST_PRODUCT_7_QUANTITY = '1';

  public const TEST_PRODUCT_8_PRODUCT_NUMBER = 'CEPM-EDG-0-CE-0-0-0';
  public const TEST_PRODUCT_8_COURSE_TITLE = 'Certified Electronics Program Manager (CEPM) Training Program and Certification Exam Bundle';
  public const TEST_PRODUCT_8_PATH = '/product/certified-electronics-program-manager-cepm-program';
  public const TEST_PRODUCT_8_COURSE_TITLE_SHORT = 'Certified Electronics Program Manager (CEPM) Program';
  public const TEST_PRODUCT_8_CART_ITEM_TITLE = 'CERTIFIED ELECTRONICS PROGRAM MANAGER (CEPM) PROGRAM';
  public const TEST_PRODUCT_8_LANG = 'English';
  public const TEST_PRODUCT_8_MODALITY = 'Online Instructor-led';
  public const TEST_PRODUCT_8_VOUCHER_TITLE = 'Certified Electronics Program Manager (CEPM) Program - English, Online Instructor-led, CEPM 020325-031325';
  public const TEST_PRODUCT_8_COURSE_LIST_TITLE = '';
  public const TEST_PRODUCT_8_DESCRIPTION = 'In the highly competitive electronics industry, the knowledge and skills of staff directly responsible for client services and program management can make or break the bottom line. The IPC Certified Electronics Program Manager (CEPM) course is designed to ensure that your team has the tools and training they need to provide service that ensures clients for life. Taught by an IPC-certified industry expert with 30 years of experience in the field, the six-week program utilizes interactive webinars, on-demand recorded training, job-specific exercises, and team projects to facilitate mastery of the key business and technical concepts required of program managers in the electronics industry.';
  public const TEST_PRODUCT_8_QUANTITY = '1';

  public const TEST_PRODUCT_EDIT_CONTENT = 'PCB Design for Manufacturability - English, Online Instructor-led, PCB Design for Manufacturability Class';
  public const TEST_PRODUCT_EDIT_CONTENT_2 = 'Electronics Assembly for Operators - Spanish, Online Self-paced Class';

  public const SPECIAL_USER_1_EMAIL = 'ats_tester@test.com';
  public const SPECIAL_USER_1_FIRST_NAME = 'ATS';
  public const SPECIAL_USER_1_LAST_NAME = 'Tester-One';
  public const SPECIAL_USER_1_DISPLAYED_NAME = 'ATS Tester-One';

  public const SPECIAL_USER_2_EMAIL = 'ats_tester_AAAAD@test.com';
  public const SPECIAL_USER_2_FIRST_NAME = 'ATS';
  public const SPECIAL_USER_2_LAST_NAME = 'Tester';
  public const SPECIAL_USER_2_DISPLAYED_NAME = 'ATS Tester';

  public const SPECIAL_USER_3_PREFIX = 'Mr.';
  public const SPECIAL_USER_3_FIRST_NAME = 'AutomatedTest';
  public const SPECIAL_USER_3_LAST_NAME = 'Three';
  public const SPECIAL_USER_3_COMPANY = 'Lockheed Martin Corporation';
  public const SPECIAL_USER_3_COMPANY_LOCATION = '4000 Memorial Pkwy SW Huntsville, AL 35802-1326 United States';
  public const SPECIAL_USER_3_EMAIL = 'autoTest3@email.com';

  public const TEST_USER_1_EMAIL = 'instructor@ipc.org';
  public const TEST_USER_1_PREFIX = 'Mr.';
  public const TEST_USER_1_FIRST_NAME = 'Instructor';
  public const TEST_USER_1_LAST_NAME = 'Tester';
  public const TEST_USER_1_COMPANY = 'Lockheed Martin Corporation';
  public const TEST_USER_1_COMPANY_LOCATION = '4000 Memorial Pkwy SW, Huntsville, AL 35802-1326, US';

  public const TEST_COMPANY_1_NAME = 'ATS Test Company';
  public const TEST_COMPANY_1_ADDRESS1 = '123 Test Street';
  public const TEST_COMPANY_1_CITY = 'Test City';
  public const TEST_COMPANY_1_STATE = 'Alabama';
  public const TEST_COMPANY_1_ZIP = 35005;

  public const TEST_COMPANY_2_NAME = 'IPC';
  public const TEST_COMPANY_2_ADDRESS1 = '3000 Lakeside Dr Ste 105N';
  public const TEST_COMPANY_2_CITY = 'Bannockburn';
  public const TEST_COMPANY_2_STATE = 'Illinois';
  public const TEST_COMPANY_2_ZIP = 60015;

}
