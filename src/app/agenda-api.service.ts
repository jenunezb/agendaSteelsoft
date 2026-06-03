import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  Activity,
  AuthSession,
  AuthUser,
  FinancialEntry,
  GeneralPending,
  PublicProfile
} from './app.models';

type RawActivity = Partial<Activity> & {
  reminder_minutes?: number | string | null;
};

type RawAuthUser = Partial<AuthUser> & {
  profile_public?: boolean | number;
  public_url?: string;
  whatsapp_number?: string | null;
  whatsapp_notifications_enabled?: boolean | number;
  telegram_chat_id?: string | null;
  telegram_notifications_enabled?: boolean | number;
};

type RawAuthSession = Omit<AuthSession, 'user'> & {
  user: RawAuthUser | null;
};

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
    return this.http
      .get<RawActivity[]>(`${this.baseUrl}/activities.php`)
      .pipe(map((activities) => activities.map((activity) => this.normalizeActivity(activity))));
  }

  getSessionStatus(): Observable<AuthSession> {
    return this.http
      .get<RawAuthSession>(`${this.baseUrl}/auth.php`)
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  getPublicProfile(username: string): Observable<PublicProfile> {
    return this.http.get<PublicProfile>(
      `${this.baseUrl}/public-profile.php?username=${encodeURIComponent(username)}`
    );
  }

  login(username: string, password: string): Observable<AuthSession> {
    return this.http
      .post<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'login',
        username,
        password
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  register(name: string, username: string, password: string): Observable<AuthSession> {
    return this.http
      .post<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'register',
        name,
        username,
        password
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  logout(): Observable<{ success: boolean }> {
    return this.http.delete<{ success: boolean }>(`${this.baseUrl}/auth.php`);
  }

  updateProfileVisibility(profilePublic: boolean): Observable<AuthSession> {
    return this.http
      .put<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'updateProfile',
        profilePublic
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  updateNotificationSettings(
    telegramChatId: string,
    telegramNotificationsEnabled: boolean
  ): Observable<AuthSession> {
    return this.http
      .put<RawAuthSession>(`${this.baseUrl}/auth.php`, {
        action: 'updateNotifications',
        telegramChatId,
        telegramNotificationsEnabled
      })
      .pipe(map((session) => this.normalizeAuthSession(session)));
  }

  createActivity(activity: Omit<Activity, 'id'>): Observable<Activity> {
    return this.http
      .post<RawActivity>(`${this.baseUrl}/activities.php`, activity)
      .pipe(map((savedActivity) => this.normalizeActivity(savedActivity)));
  }

  updateActivity(id: number, activity: Omit<Activity, 'id'>): Observable<Activity> {
    return this.http
      .put<RawActivity>(`${this.baseUrl}/activities.php?id=${id}`, activity)
      .pipe(map((savedActivity) => this.normalizeActivity(savedActivity)));
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

  private normalizeReminderMinutes(value: number | string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
      return null;
    }

    const normalizedValue = Number(value);
    if (!Number.isFinite(normalizedValue)) {
      return null;
    }

    return normalizedValue >= 1 && normalizedValue <= 1440 ? normalizedValue : null;
  }

  private normalizeActivity(activity: RawActivity): Activity {
    return {
      id: Number(activity.id) || 0,
      title: activity.title?.trim() ?? '',
      startTime: activity.startTime?.trim() ?? '',
      endTime: activity.endTime?.trim() ?? '',
      assignee: activity.assignee?.trim() ?? '',
      visibility: activity.visibility === 'public' ? 'public' : 'private',
      completed: Boolean(activity.completed),
      location: activity.location?.trim() ?? '',
      description: activity.description ?? '',
      date: this.normalizeApiDate(activity.date),
      reminderMinutes: this.normalizeReminderMinutes(
        activity.reminderMinutes ?? activity.reminder_minutes
      )
    };
  }

  private normalizeAuthSession(session: RawAuthSession): AuthSession {
    return {
      authenticated: Boolean(session.authenticated),
      canRegister: Boolean(session.canRegister),
      user: session.user ? this.normalizeAuthUser(session.user) : null
    };
  }

  private normalizeAuthUser(user: RawAuthUser): AuthUser {
    return {
      id: Number(user.id) || 0,
      name: user.name?.trim() ?? '',
      username: user.username?.trim() ?? '',
      profilePublic: Boolean(user.profilePublic ?? user.profile_public),
      publicUrl: user.publicUrl?.trim() ?? user.public_url?.trim() ?? '',
      whatsappNumber: user.whatsappNumber?.trim() ?? user.whatsapp_number?.trim() ?? '',
      whatsappNotificationsEnabled: Boolean(
        user.whatsappNotificationsEnabled ?? user.whatsapp_notifications_enabled
      ),
      telegramChatId: user.telegramChatId?.trim() ?? user.telegram_chat_id?.trim() ?? '',
      telegramNotificationsEnabled: Boolean(
        user.telegramNotificationsEnabled ?? user.telegram_notifications_enabled
      )
    };
  }
}
