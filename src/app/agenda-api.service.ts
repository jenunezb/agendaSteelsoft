import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { Activity, FinancialEntry, GeneralPending } from './app.models';

type RawFinancialEntry = Partial<FinancialEntry> & {
  entry_type?: FinancialEntry['type'];
  entry_date?: string;
};

@Injectable({
  providedIn: 'root'
})
export class AgendaApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = '/api';

  getActivities(): Observable<Activity[]> {
    return this.http.get<Activity[]>(`${this.baseUrl}/activities.php`);
  }

  createActivity(activity: Omit<Activity, 'id'>): Observable<Activity> {
    return this.http.post<Activity>(`${this.baseUrl}/activities.php`, activity);
  }

  updateActivity(id: number, activity: Omit<Activity, 'id'>): Observable<Activity> {
    return this.http.put<Activity>(`${this.baseUrl}/activities.php?id=${id}`, activity);
  }

  deleteActivity(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/activities.php?id=${id}`);
  }

  getGeneralPendings(): Observable<GeneralPending[]> {
    return this.http.get<GeneralPending[]>(`${this.baseUrl}/pendings.php`);
  }

  createGeneralPending(pending: Omit<GeneralPending, 'id'>): Observable<GeneralPending> {
    return this.http.post<GeneralPending>(`${this.baseUrl}/pendings.php`, pending);
  }

  updateGeneralPending(
    id: number,
    pending: Omit<GeneralPending, 'id'>
  ): Observable<GeneralPending> {
    return this.http.put<GeneralPending>(`${this.baseUrl}/pendings.php?id=${id}`, pending);
  }

  deleteGeneralPending(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/pendings.php?id=${id}`);
  }

  getFinancialEntries(): Observable<FinancialEntry[]> {
    return this.http
      .get<RawFinancialEntry[]>(`${this.baseUrl}/financial-entries.php`)
      .pipe(map((entries) => entries.map((entry) => this.normalizeFinancialEntry(entry))));
  }

  createFinancialEntry(entry: Omit<FinancialEntry, 'id'>): Observable<FinancialEntry> {
    return this.http
      .post<RawFinancialEntry>(`${this.baseUrl}/financial-entries.php`, entry)
      .pipe(map((savedEntry) => this.normalizeFinancialEntry(savedEntry)));
  }

  updateFinancialEntry(
    id: number,
    entry: Omit<FinancialEntry, 'id'>
  ): Observable<FinancialEntry> {
    return this.http
      .put<RawFinancialEntry>(`${this.baseUrl}/financial-entries.php?id=${id}`, entry)
      .pipe(map((savedEntry) => this.normalizeFinancialEntry(savedEntry)));
  }

  deleteFinancialEntry(id: number): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/financial-entries.php?id=${id}`);
  }

  private normalizeApiDate(rawDate: string | null | undefined): string {
    if (typeof rawDate !== 'string') {
      return '';
    }

    const trimmedDate = rawDate.trim();

    if (!trimmedDate) {
      return '';
    }

    const isoMatch = trimmedDate.match(/^(\d{4}-\d{2}-\d{2})/);
    if (isoMatch) {
      return isoMatch[1];
    }

    const localMatch = trimmedDate.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (localMatch) {
      const [, day, month, year] = localMatch;
      return `${year}-${month}-${day}`;
    }

    return '';
  }

  private normalizeFinancialEntry(entry: RawFinancialEntry): FinancialEntry {
    return {
      id: Number(entry.id) || 0,
      title: entry.title?.trim() ?? '',
      type: entry.type === 'expense' || entry.entry_type === 'expense' ? 'expense' : 'income',
      amount: Number(entry.amount) || 0,
      assignee: entry.assignee?.trim() ?? '',
      description: entry.description ?? '',
      date: this.normalizeApiDate(entry.date ?? entry.entry_date)
    };
  }
}
