# Heidi Calls | Harbour to Sunset GP

**Project: Intelligent Voicemail Triage System**

This project is a prototype designed for Heidi Calls to help medical clinics like Harbour to Sunset GP manage high volumes of inbound after-hours and overflow voicemails. It transforms unstructured audio recordings into structured, actionable work items for clinic staff.

## The Problem
Harbour to Sunset GP faces a significant administrative bottleneck every morning. Staff arrive to dozens of long, unstructured voicemails with no clear sense of urgency or intent, forcing them to spend hours listening and triaging manually.

## The Solution
An intelligent triage dashboard that:
- **Extracts Intent & Urgency**: Automatically identifies the reason for the call (Prescription Refill, Appointment Booking, Urgent Issue, etc.) and assigns a priority score (High, Medium, Low).
- **Summarizes Action Items**: Provides a concise clinical summary and suggested next steps, reducing the need to listen to full recordings.
- **Streams Real-time Analysis**: Uses streaming AI to surface insights as they are being generated.
- **Persistent Management**: Tracks the status of each voicemail (New, In Progress, Resolved) using local storage for zero-database overhead.

## Tech Stack
- **Backend**: Vanilla PHP 8.2 (No frameworks or external packages).
- **Frontend**: Tailwind CSS (via CDN) and Vanilla JavaScript.
- **AI Integration**: 
  - **Transcription**: OpenRouter API (`google/chirp-3` model).
  - **Analysis**: OpenRouter API (`google/gemini-2.0-flash-lite` or similar).
- **Storage**: Browser `localStorage` for state management and local `/audio` folder for recordings.

## Getting Started
1. Clone this repository.
2. Ensure you have a local PHP server running (e.g., `php -S localhost:8000`).
3. Open `index.php` and replace `'YOUR_OPENROUTER_API_KEY'` with your actual [OpenRouter](https://openrouter.ai/) API key.
4. Place any `.wav` or `.mp3` sample files in the `/audio` folder.
5. Open the project in your browser.

## Key Features
- **Morning Triage Dashboard**: Surfaces high-priority items first to ensure urgent patient needs are met immediately.
- **AI-Powered Analysis**: Extracts patient names, DOBs, and justifications for clinical urgency.
- **Integrated Audio Player**: A custom minimalist player for verification if staff need to hear specific segments.
- **Zero Configuration**: Runs natively on PHP with no database setup required.

---
*Created for the Heidi Health Project 2 - Intelligent Voicemail Application.*
