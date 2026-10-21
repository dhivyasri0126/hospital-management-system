# Hospital Management System

A basic hospital management web application built with HTML, CSS, JavaScript, PHP and MySQL. Designed for a college project and local XAMPP development.

## Features

- Role-based login for admin, doctor, patient and pharmacist
- Patient self-registration
- Doctor directory and appointment booking
- Admin/doctor appointment status updates
- Doctor medical records and optional prescriptions
- Invoice creation and demo payment status tracking
- Pharmacy medicine inventory and stock updates
- Prepared SQL statements, password hashing, session regeneration and CSRF tokens
- Responsive dashboard and printable billing page

## Requirements

- XAMPP (Apache, PHP 8.1+ recommended, MySQL/MariaDB)
- A modern browser

## Installation on Windows with XAMPP

1. Extract the `hospital-management-system` folder into `C:\xampp\htdocs\`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Open `http://localhost/phpmyadmin`.
4. Import `database/hospital_db.sql` using the Import tab. The script creates `hospital_db`.
5. Check `config/database.php`. The default XAMPP database user is `root` with an empty password. Change these values if your setup differs.
6. Visit `http://localhost/hospital-management-system/setup.php` once to create demo users and a demo doctor.
7. Visit `http://localhost/hospital-management-system/` and sign in with one of the demo accounts below.

## Demo accounts

| Role | Email | Password |
|---|---|---|
| Admin | `admin@hospital.test` | `Admin@123` |
| Doctor | `doctor@hospital.test` | `Doctor@123` |
| Patient | `patient@hospital.test` | `Patient@123` |
| Pharmacist | `pharmacy@hospital.test` | `Pharmacy@123` |

New patient accounts can also be created from the registration page.

## Role access

- **Admin:** Dashboard, doctors, all appointments, all records, billing and pharmacy inventory.
- **Doctor:** Assigned appointments, patient records for assigned appointments, create invoices for their appointments.
- **Patient:** Book appointments, view own appointment history, own records and own invoices.
- **Pharmacist:** Dashboard, doctor directory, pharmacy inventory.

## Basic workflow to test

1. Sign in as the patient and book an appointment with Dr. Priya Kumar.
2. Sign out, sign in as the doctor, then approve the appointment.
3. As the doctor, add a medical record and optional prescription.
4. Create an invoice for the appointment.
5. Sign in as the patient to review the record and invoice. The patient can mark the invoice as paid in this demo.
6. Sign in as the pharmacist or admin to add medicines and update stock.

## Important notes

- This is an educational demo, not a production-ready clinical system.
- The “Mark paid” action only changes a database status. It does not process money or connect to a payment gateway.
- The sample app does not implement email/SMS notifications, appointment slot collision prevention, or a full audit trail.
- Medical and billing data are sensitive. Before real use, add HTTPS, backups, stricter authorization and audit logging, secure deployment configuration, privacy/legal review, and professional security testing.
- Change demo passwords and delete or restrict `setup.php` after the initial setup.
